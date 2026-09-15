<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Payload keys that describe relations rather than columns.
     *
     * @var array<int, string>
     */
    private const RELATION_KEYS = ['member_ids', 'lead_ids'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Team::class);

        $teams = Team::query()
            ->withCount('members')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderBy('name')
            ->paginate(config('ticktz.per_page.admin'))
            ->withQueryString();

        return Inertia::render('Admin/Teams/Index', [
            'teams' => $teams,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Team::class);

        return Inertia::render('Admin/Teams/Form', [
            'team' => null,
            'agents' => $this->agentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Team::class);

        $data = $this->validateTeam($request, null);

        $team = Team::query()->create(Arr::except($data, self::RELATION_KEYS));
        $this->syncMembers($team, $data);

        $this->audit->created($team, "Created team {$team->name}");

        return redirect()->route('admin.teams.index')
            ->with('success', __('admin.teams.created', ['name' => $team->name]));
    }

    public function edit(Team $team): Response
    {
        $this->authorize('update', $team);

        $team->load('members:id,name');

        return Inertia::render('Admin/Teams/Form', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'description' => $team->description,
                'email' => $team->email,
                'is_active' => $team->is_active,
                'member_ids' => $team->members->pluck('id')->all(),
                'lead_ids' => $team->members->filter(fn (User $member) => $member->pivot->role === 'lead')->pluck('id')->values()->all(),
            ],
            'agents' => $this->agentOptions(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('update', $team);

        $data = $this->validateTeam($request, $team);

        $team->fill(Arr::except($data, self::RELATION_KEYS))->save();
        $this->syncMembers($team, $data);

        $this->audit->updated($team, "Updated team {$team->name}");

        return redirect()->route('admin.teams.index')
            ->with('success', __('admin.teams.updated', ['name' => $team->name]));
    }

    public function destroy(Team $team): RedirectResponse
    {
        $this->authorize('delete', $team);

        $this->audit->deleted($team, "Deleted team {$team->name}");
        $team->delete();

        return redirect()->route('admin.teams.index')->with('success', __('admin.teams.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTeam(Request $request, ?Team $team): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('teams', 'slug')->ignore($team?->getKey())],
            'description' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', Rule::exists('users', 'id')],
            'lead_ids' => ['array'],
            'lead_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMembers(Team $team, array $data): void
    {
        $leads = array_map('intval', $data['lead_ids'] ?? []);

        $members = collect($data['member_ids'] ?? [])
            ->map('intval')
            ->unique()
            ->mapWithKeys(fn (int $id) => [$id => ['role' => in_array($id, $leads, true) ? 'lead' : 'member']])
            ->all();

        $team->members()->sync($members);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agentOptions(): array
    {
        return User::query()
            ->active()
            ->agents()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();
    }
}
