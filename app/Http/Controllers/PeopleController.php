<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\Assignability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Searching for a person, for every picker that needs one.
 *
 * A desk with four hundred agents cannot put them in a `<select>`, and the ones
 * this replaced did not try: they took the first five hundred users by name and
 * dropped the rest without saying so. Nobody called Zwart was assignable, and
 * nothing anywhere said why.
 *
 * So the list is never sent whole. The browser asks for the few that match what
 * has been typed, and the server decides what "match" and "the few" mean —
 * which also means the assignable set is decided in one place rather than
 * assembled by whichever page happens to be rendering. That second part is the
 * one that was actually broken: the ticket sidebar offered every agent while
 * the server refused anybody off the ticket's team, so the rule reached the
 * operator as an error message rather than as a shorter list.
 *
 * The scopes are deliberately separate rather than one endpoint that returns
 * everybody and lets the caller filter. A picker that offers a name the server
 * will then refuse is worse than no picker: it reads as a bug in the product
 * rather than as a rule somebody walked into.
 *
 * Mounted outside both /agent and /admin because it serves both. Half the
 * screens that pick a person are administrative — team membership, a user's
 * manager, an approval step, an automation action — and none of those
 * permissions implies `tickets.view`.
 */
class PeopleController extends Controller
{
    /** How many names a person can usefully choose between without scrolling. */
    private const LIMIT = 10;

    /**
     * Who may search which set.
     *
     * The principle, rather than a list somebody extends by habit: a picker may
     * only show a list this person could already have seen by navigating the
     * product. `agent` is the staff directory, which anybody in the agent
     * console reads off the assignee column and anybody administering teams,
     * automation, approvals or SLA reads off their own screens. `user` includes
     * customers, so it is the narrower set — the screens that file a ticket for
     * somebody, or manage accounts.
     *
     * Adding a scope here without asking which existing screen already shows
     * that list is how a picker becomes a directory export.
     *
     * @var array<string, array<int, string>>
     */
    private const MAY_SEARCH = [
        'agent' => [
            'tickets.view', 'users.manage', 'teams.manage',
            'automation.manage', 'approvals.manage', 'sla.manage',
        ],
        'user' => [
            'tickets.create', 'tickets.update', 'users.manage', 'approvals.manage',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'scope' => ['required', Rule::in(['assignee', 'agent', 'user'])],
            'ticket' => ['nullable', 'string', 'max:64'],
            'team_id' => ['nullable', 'integer'],
            'queue_id' => ['nullable', 'integer'],
        ]);

        $query = match ($data['scope']) {
            'assignee' => $this->assignable($request, $data),
            'agent' => $this->permitted($request, 'agent', User::query()->active()->agents()),
            'user' => $this->permitted($request, 'user', User::query()->active()),
        };

        $people = $query
            ->search($data['q'] ?? '')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (User $user) => $user->toSummaryArray())
            ->all();

        return response()->json(['people' => $people]);
    }

    /**
     * The agents this ticket may be given to.
     *
     * Authorised against the ticket when there is one, because `assign` is a
     * permission *and* a matter of whether this agent can see that ticket at
     * all — a picker that answers for a ticket in somebody else's queue is a
     * way to read a team's staffing from outside it.
     *
     * @param  array<string, mixed>  $data
     * @return Builder<User>
     */
    private function assignable(Request $request, array $data): Builder
    {
        if (($key = $data['ticket'] ?? null) !== null) {
            $ticket = Ticket::query()->where('key', $key)->firstOrFail();

            $this->authorize('assign', $ticket);

            return Assignability::query($ticket);
        }

        // No ticket yet: the fields somebody has filled in so far decide. The
        // permission is the same one the form will need to save it.
        $this->authorize('tickets.assign');

        // Cast rather than taken from the validated array: a query string is
        // strings all the way down, and `integer` validates the shape without
        // changing the type. `?: null` because absent reads back as 0, and a
        // team 0 is a team nobody is on.
        return Assignability::queryForTeam(Assignability::teamIdForAttributes(
            $request->integer('team_id') ?: null,
            $request->integer('queue_id') ?: null,
        ));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function permitted(Request $request, string $scope, Builder $query): Builder
    {
        abort_unless(
            $request->user()?->hasAnyPermission(...self::MAY_SEARCH[$scope]) ?? false,
            403,
        );

        return $query;
    }
}
