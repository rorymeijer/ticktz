<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account administration. Every action is authorised through UserPolicy and
 * recorded in the audit trail.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['roles:id,name,display_name', 'organization:id,name'])
            ->search($request->string('search')->toString())
            ->when($request->filled('role'), fn ($query) => $query->whereHas(
                'roles',
                fn ($roles) => $roles->where('name', $request->string('role')->toString())
            ))
            ->when($request->filled('directory'), fn ($query) => $query->where('directory', $request->string('directory')->toString()))
            ->when($request->string('status')->toString() === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when($request->string('status')->toString() === 'active', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->paginate(config('ticktz.per_page.admin'))
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'initials' => $user->initials(),
                'avatar_color' => $user->avatarColor(),
                'directory' => $user->directory,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'roles' => $user->roles->map(fn (Role $role) => [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                ])->all(),
                'organization' => $user->organization?->only('id', 'name'),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only('search', 'role', 'directory', 'status'),
            'roles' => Role::query()->orderBy('position')->get(['id', 'name', 'display_name']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/Users/Form', $this->formOptions() + ['user' => null]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? config('app.locale'),
            'organization_id' => $data['organization_id'] ?? null,
            'manager_id' => $data['manager_id'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'directory' => 'local',
        ]);

        $this->syncRelations($request, $user, $data);

        $this->audit->created($user, "Created account {$user->email}");

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('admin.users.created', ['name' => $user->name]));
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $user->load(['roles:id,name', 'teams:id,name']);

        return Inertia::render('Admin/Users/Form', $this->formOptions() + [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'locale' => $user->locale,
                'organization_id' => $user->organization_id,
                'manager_id' => $user->manager_id,
                'job_title' => $user->job_title,
                'phone' => $user->phone,
                'signature' => $user->signature,
                'is_active' => $user->is_active,
                'directory' => $user->directory,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'role_ids' => $user->roles->pluck('id')->all(),
                'team_ids' => $user->teams->pluck('id')->all(),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        // Directory-managed identity is owned by LDAP, not by this form.
        if ($user->isDirectoryManaged()) {
            unset($data['name'], $data['email'], $data['username'], $data['password']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        // Relations are synced separately; they are not columns on `users`.
        $user->fill(Arr::except($data, ['role_ids', 'team_ids', 'password_confirmation']))->save();

        $this->syncRelations($request, $user, $data);

        $this->audit->updated($user, "Updated account {$user->email}");

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('admin.users.updated', ['name' => $user->name]));
    }

    /**
     * Deactivate rather than delete: tickets, comments and audit entries keep
     * pointing at the account.
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $user->forceFill(['is_active' => false])->save();
        $user->delete();

        $this->audit->deleted($user, "Deactivated and archived account {$user->email}");

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('admin.users.deleted', ['name' => $user->name]));
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $this->audit->log(
            $user,
            $user->is_active ? 'activated' : 'deactivated',
            $user->is_active ? "Activated account {$user->email}" : "Deactivated account {$user->email}",
        );

        return back()->with('success', __($user->is_active ? 'admin.users.activated' : 'admin.users.deactivated', [
            'name' => $user->name,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(Request $request, User $user, array $data): void
    {
        if (array_key_exists('role_ids', $data) && $request->user()->can('assignRoles', $user)) {
            $user->roles()->sync($data['role_ids'] ?? []);
        }

        if (array_key_exists('team_ids', $data)) {
            $user->teams()->sync($data['team_ids'] ?? []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'roles' => Role::query()->orderBy('position')->get(['id', 'name', 'display_name', 'description', 'scope']),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Candidate managers, for approval steps that ask for "the
            // requester's manager". Agents only: somebody who cannot sign in
            // to answer an approval is not a useful answer to that question.
            'managers' => User::query()->active()->agents()->orderBy('name')->get(['id', 'name', 'email']),
            'canAssignRoles' => request()->user()->hasPermission('roles.manage'),
        ];
    }
}
