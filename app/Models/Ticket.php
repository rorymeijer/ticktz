<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasCustomFields;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A request being worked on.
 *
 * Tickets are addressed by their human-readable `key` (SUP-1042) everywhere a
 * person sees them, and by their id internally. Route binding uses the key, so
 * a URL that ends up in an e-mail stays meaningful.
 *
 * @property string $key
 * @property string $subject
 * @property string|null $description
 * @property string $source
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use Auditable, HasCustomFields, HasFactory, SoftDeletes;

    public const SOURCES = ['portal', 'email', 'agent', 'api', 'automation'];

    protected $fillable = [
        'subject', 'description', 'status_id', 'priority_id', 'workflow_id',
        'queue_id', 'request_type_id', 'email_channel_id', 'requester_id',
        'assignee_id', 'team_id', 'organization_id', 'created_by', 'source',
        'sla_policy_id',
    ];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_public_reply_at' => 'datetime',
            'last_requester_reply_at' => 'datetime',
            'sla_due_at' => 'immutable_datetime',
            'sla_breached' => 'boolean',
            'number' => 'integer',
            'reopen_count' => 'integer',
        ];
    }

    /**
     * Tickets are looked up by key in URLs.
     */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    /** @return BelongsTo<TicketStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    /** @return BelongsTo<Priority, $this> */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<Queue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at');
    }

    /** @return HasMany<Comment, $this> */
    public function publicComments(): HasMany
    {
        return $this->comments()->where('is_internal', false);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')
            ->withPivot('is_automatic')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'ticket_labels');
    }

    /** @return HasMany<InboundMessage, $this> */
    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    /** @return BelongsTo<EmailChannel, $this> */
    public function emailChannel(): BelongsTo
    {
        return $this->belongsTo(EmailChannel::class, 'email_channel_id');
    }

    /** @return BelongsToMany<KbArticle, $this> */
    public function kbArticles(): BelongsToMany
    {
        return $this->belongsToMany(KbArticle::class, 'kb_article_ticket')
            ->withPivot('linked_by')
            ->withTimestamps();
    }

    /** @return HasMany<SlaTimer, $this> */
    public function slaTimers(): HasMany
    {
        return $this->hasMany(SlaTimer::class);
    }

    /** @return HasMany<SlaEvent, $this> */
    public function slaEvents(): HasMany
    {
        return $this->hasMany(SlaEvent::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /**
     * The clock that matters right now: the live timer closest to breaching.
     *
     * When nothing is still running, a timer that was missed is shown instead.
     * A ticket the breached filter returns must carry a badge saying so —
     * otherwise a resolved-but-missed ticket appears in the list with an empty
     * cell, which reads as a bug rather than as history.
     */
    public function primarySlaTimer(): ?SlaTimer
    {
        if (! $this->relationLoaded('slaTimers')) {
            return null;
        }

        $live = $this->slaTimers
            ->filter(fn (SlaTimer $timer): bool => $timer->isLive())
            ->sortBy('due_at')
            ->first();

        return $live ?? $this->slaTimers
            ->filter(fn (SlaTimer $timer): bool => $timer->breached_at !== null)
            ->sortByDesc('breached_at')
            ->first();
    }

    /** @return HasMany<TicketLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(TicketLink::class);
    }

    /** @return HasMany<TicketLink, $this> */
    public function inverseLinks(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'related_ticket_id');
    }

    // ---------------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------------

    /**
     * @param  Builder<Ticket>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereHas('status', fn (Builder $status) => $status->whereIn('category', TicketStatus::OPEN_CATEGORIES));
    }

    /**
     * Restrict a query to what the given user is allowed to see.
     *
     * This is the single source of truth for ticket visibility: the policy
     * calls the same rules for a single record, and every list query runs
     * through here. Getting it wrong in two places is how data leaks.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermission('tickets.view.all')) {
            return;
        }

        if ($user->hasPermission('tickets.view')) {
            // An agent without the "all" permission sees their own work, their
            // teams' work, and anything they were explicitly added to.
            $teamIds = $user->teamIds();

            $query->where(function (Builder $scoped) use ($user, $teamIds): void {
                $scoped->where('assignee_id', $user->getKey())
                    ->orWhere('created_by', $user->getKey())
                    ->orWhereIn('team_id', $teamIds)
                    ->orWhereHas('queue', fn (Builder $queue) => $queue->whereIn('team_id', $teamIds))
                    ->orWhereHas('watchers', fn (Builder $watchers) => $watchers->where('users.id', $user->getKey()));
            });

            return;
        }

        // A requester sees their own tickets, plus their organisation's when
        // that organisation shares visibility.
        $query->where(function (Builder $scoped) use ($user): void {
            $scoped->where('requester_id', $user->getKey())
                ->orWhereHas('watchers', fn (Builder $watchers) => $watchers->where('users.id', $user->getKey()));

            if ($user->organization_id && $user->organization?->shared_ticket_visibility) {
                $scoped->orWhere('organization_id', $user->organization_id);
            }
        });
    }

    // ---------------------------------------------------------------------
    // Behaviour
    // ---------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status?->isOpen() ?? false;
    }

    public function isAssigned(): bool
    {
        return $this->assignee_id !== null;
    }

    /**
     * Age of the ticket in seconds, stopping at the moment it was resolved.
     */
    public function ageInSeconds(): int
    {
        $end = $this->resolved_at ?? $this->closed_at ?? now();

        return max(0, $this->created_at->diffInSeconds($end));
    }

    /**
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'subject' => $this->subject,
            'status' => $this->status?->toSummaryArray(),
            'priority' => $this->priority?->toSummaryArray(),
            'requester' => $this->requester?->toSummaryArray(),
            'assignee' => $this->assignee?->toSummaryArray(),
            'team' => $this->team?->only('id', 'name'),
            'organization' => $this->organization?->only('id', 'name'),
            'labels' => $this->relationLoaded('labels')
                ? $this->labels->map(fn (Label $label) => $label->toSummaryArray())->all()
                : [],
            'source' => $this->source,
            'sla' => $this->primarySlaTimer()?->toDisplayArray(),
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),
            'sla_breached' => (bool) $this->sla_breached,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
        ];
    }
}
