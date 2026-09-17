<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\Queue;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Translates a filter array — from a queue definition or from the query
 * string — into a ticket query.
 *
 * One implementation serves both so that "what a queue shows" and "what the
 * filter bar shows" can never drift apart, and so a saved queue is nothing
 * more special than a stored set of these keys.
 */
class TicketFilter
{
    /**
     * Filter keys this class understands. Anything else is ignored rather than
     * silently widening the result set.
     */
    public const KEYS = [
        'search', 'status', 'status_category', 'priority', 'assignee', 'team',
        'queue', 'requester', 'organization', 'label', 'source',
        'created_from', 'created_to', 'updated_from', 'updated_to', 'unanswered',
        'sla',
    ];

    /**
     * What the SLA filter can ask for. `at_risk` is the one agents live in:
     * still on track, but not for much longer.
     *
     * @var array<int, string>
     */
    public const SLA_STATES = ['breached', 'at_risk', 'running', 'paused', 'none'];

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function apply(Builder $query, array $filters, User $viewer): Builder
    {
        $filters = Arr::only($filters, self::KEYS);

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            match ($key) {
                'search' => $this->applySearch($query, (string) $value),
                'status' => $query->whereIn('status_id', $this->ids($value)),
                'status_category' => $query->whereHas(
                    'status',
                    fn (Builder $status) => $status->whereIn('category', $this->strings($value))
                ),
                'priority' => $query->whereIn('priority_id', $this->ids($value)),
                'assignee' => $this->applyAssignee($query, $value, $viewer),
                'team' => $this->applyTeam($query, $value, $viewer),
                'queue' => $query->whereIn('queue_id', $this->ids($value)),
                'requester' => $query->whereIn('requester_id', $this->ids($value)),
                'organization' => $query->whereIn('organization_id', $this->ids($value)),
                'label' => $query->whereHas(
                    'labels',
                    fn (Builder $labels) => $labels->whereIn('labels.id', $this->ids($value))
                ),
                'source' => $query->whereIn('source', $this->strings($value)),
                'created_from' => $query->where('tickets.created_at', '>=', $this->date($value)),
                'created_to' => $query->where('tickets.created_at', '<=', $this->date($value, endOfDay: true)),
                'updated_from' => $query->where('tickets.updated_at', '>=', $this->date($value)),
                'updated_to' => $query->where('tickets.updated_at', '<=', $this->date($value, endOfDay: true)),
                'unanswered' => $this->applyUnanswered($query),
                'sla' => $this->applySla($query, (string) $value),
                default => null,
            };
        }

        return $query;
    }

    /**
     * Filter by how a ticket is doing against its promise.
     *
     * These read the denormalised columns on `tickets` rather than joining
     * `sla_timers`: the whole reason those columns exist is that a list of ten
     * thousand tickets must not join a second table per row.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySla(Builder $query, string $state): void
    {
        match ($state) {
            'breached' => $query->where('tickets.sla_breached', true),
            // Approaching, but not yet missed. An hour is the window an agent
            // can still do something about.
            'at_risk' => $query->where('tickets.sla_breached', false)
                ->whereNotNull('tickets.sla_due_at')
                ->whereBetween('tickets.sla_due_at', [Carbon::now(), Carbon::now()->addHour()]),
            'running' => $query->where('tickets.sla_breached', false)
                ->whereNotNull('tickets.sla_due_at')
                ->whereHas('slaTimers', fn (Builder $timers) => $timers->where('status', 'running')),
            'paused' => $query->whereHas(
                'slaTimers',
                fn (Builder $timers) => $timers->where('status', 'paused')
            ),
            // Nothing is being measured: no policy claimed it, or every clock
            // has finished.
            'none' => $query->whereNull('tickets.sla_due_at'),
            default => null,
        };
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function applyQueue(Builder $query, Queue $queue, User $viewer): Builder
    {
        return $this->apply($query, $queue->filters ?? [], $viewer);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function applySort(Builder $query, ?string $sortBy, ?string $direction): Builder
    {
        $column = in_array($sortBy, Queue::SORTABLE, true) ? $sortBy : 'updated_at';
        $direction = mb_strtolower((string) $direction) === 'asc' ? 'asc' : 'desc';

        // Priority sorts by urgency, not by the arbitrary id of the row.
        if ($column === 'priority_id') {
            return $query
                ->leftJoin('priorities', 'priorities.id', '=', 'tickets.priority_id')
                ->orderBy('priorities.level', $direction === 'asc' ? 'asc' : 'desc')
                ->select('tickets.*');
        }

        return $query->orderBy("tickets.{$column}", $direction)->orderBy('tickets.id', $direction);
    }

    /**
     * Free-text search.
     *
     * On MySQL the FULLTEXT index does the ranking, with a LIKE pass beside
     * it — not instead of it. InnoDB's full-text index misses things a person
     * searching a service desk very much expects to find: words shorter than
     * `innodb_ft_min_token_size` (three by default, so "VPN" is on the edge
     * and "AD" never matches), stopwords, and partial words — "verbind" finds
     * nothing in "verbinding". A search box that silently returns nothing for
     * those is broken from the user's side, whatever the index thinks.
     *
     * It also cannot see rows written in an uncommitted transaction, which is
     * what every test runs inside. That is how this was found: three tests
     * passed on SQLite and failed on MySQL.
     *
     * The LIKE pass costs nothing extra in plan terms: the `key` match beside
     * it is already a leading-wildcard LIKE, so this query never used the
     * full-text index alone in the first place.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        // "SUP-1042" is a key, not a phrase — jump straight to it.
        if (preg_match('/^[A-Za-z]{1,10}-\d+$/', $term)) {
            $query->where('tickets.key', mb_strtoupper($term));

            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $scoped) use ($term, $like): void {
            if ($scoped->getConnection()->getDriverName() === 'mysql') {
                $scoped->whereFullText(['subject', 'description'], $term);
            }

            $scoped->orWhere('tickets.subject', 'like', $like)
                ->orWhere('tickets.description', 'like', $like)
                ->orWhere('tickets.key', 'like', $like);
        });
    }

    /**
     * `unassigned`, `me`, or a list of user ids.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyAssignee(Builder $query, mixed $value, User $viewer): void
    {
        $values = $this->strings($value);

        if (in_array('unassigned', $values, true) && count($values) === 1) {
            $query->whereNull('assignee_id');

            return;
        }

        $ids = collect($values)
            ->map(fn (string $item) => $item === 'me' ? (string) $viewer->getKey() : $item)
            ->filter(fn (string $item) => is_numeric($item))
            ->map(fn (string $item) => (int) $item)
            ->all();

        $includeUnassigned = in_array('unassigned', $values, true);

        $query->where(function (Builder $scoped) use ($ids, $includeUnassigned): void {
            if ($ids !== []) {
                $scoped->whereIn('assignee_id', $ids);
            }

            if ($includeUnassigned) {
                $scoped->orWhereNull('assignee_id');
            }
        });
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyTeam(Builder $query, mixed $value, User $viewer): void
    {
        $values = $this->strings($value);

        if (in_array('mine', $values, true)) {
            $query->whereIn('team_id', $viewer->teamIds() ?: [0]);

            return;
        }

        $query->whereIn('team_id', $this->ids($value));
    }

    /**
     * Tickets where the requester spoke last — the ones somebody still owes an
     * answer.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applyUnanswered(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->whereIn('category', TicketStatus::OPEN_CATEGORIES))
            ->where(function (Builder $scoped): void {
                $scoped->whereNull('last_public_reply_at')
                    ->orWhereColumn('last_requester_reply_at', '>', 'last_public_reply_at');
            });
    }

    /**
     * @return array<int, int>
     */
    private function ids(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn ($item) => is_numeric($item))
            ->map(fn ($item) => (int) $item)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => (string) $item)
            ->unique()
            ->values()
            ->all();
    }

    private function date(mixed $value, bool $endOfDay = false): Carbon
    {
        $date = Carbon::parse((string) $value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
