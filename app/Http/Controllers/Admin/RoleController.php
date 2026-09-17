<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount(['users', 'permissions'])
            ->orderBy('position')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'scope' => $role->scope,
                'is_super' => $role->is_super,
                'is_system' => $role->is_system,
                'is_default' => $role->is_default,
                'users_count' => $role->users_count,
                'permissions_count' => $role->is_super
                    ? count(PermissionCatalog::all())
                    : $role->permissions_count,
            ]);

        return Inertia::render('Admin/Roles/Index', ['roles' => $roles]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Admin/Roles/Form', [
            'role' => null,
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $data = $this->validateRole($request, null);

        $role = Role::query()->create([
            'name' => $data['name'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'],
            'is_default' => $data['is_default'] ?? false,
            'is_super' => false,
            'is_system' => false,
            'position' => 100,
        ]);

        $role->syncPermissionNames($data['permissions'] ?? []);
        $this->ensureSingleDefault($role);

        $this->audit->created($role, "Created role {$role->name}");

        return redirect()->route('admin.roles.index')
            ->with('success', __('admin.roles.created', ['name' => $role->display_name]));
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        $role->load('permissions:id,name');

        return Inertia::render('Admin/Roles/Form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'scope' => $role->scope,
                'is_super' => $role->is_super,
                'is_system' => $role->is_system,
                'is_default' => $role->is_default,
                'permissions' => $role->permissions->pluck('name')->all(),
            ],
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $data = $this->validateRole($request, $role);

        $role->fill([
            // A system role keeps its slug and scope; only its label,
            // description and permission set are editable.
            'name' => $role->is_system ? $role->name : $data['name'],
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'scope' => $role->is_system ? $role->scope : $data['scope'],
            'is_default' => $data['is_default'] ?? false,
        ])->save();

        if (! $role->is_super) {
            $role->syncPermissionNames($data['permissions'] ?? []);
        }

        $this->ensureSingleDefault($role);

        $this->audit->updated($role, "Updated role {$role->name}");

        return redirect()->route('admin.roles.index')
            ->with('success', __('admin.roles.updated', ['name' => $role->display_name]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        // A super-admin bypasses policies, so the system-role guard is
        // repeated here: Ticktz cannot function without these three roles.
        abort_if($role->isProtected(), 403);

        if ($role->users()->exists()) {
            return back()->with('error', __('admin.roles.in_use'));
        }

        $this->audit->deleted($role, "Deleted role {$role->name}");
        $role->delete();

        return redirect()->route('admin.roles.index')->with('success', __('admin.roles.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRole(Request $request, ?Role $role): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('roles', 'name')->ignore($role?->getKey()),
            ],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', Rule::in(['admin', 'agent', 'requester'])],
            'is_default' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::all())],
        ], [
            'name.regex' => __('admin.roles.slug_format'),
        ]);
    }

    /**
     * Exactly one role may be the default for new accounts.
     */
    private function ensureSingleDefault(Role $role): void
    {
        if ($role->is_default) {
            Role::query()->whereKeyNot($role->getKey())->update(['is_default' => false]);
        }
    }

    /**
     * Permission catalogue enriched with the id and translated label the admin
     * UI renders.
     *
     * @return array<int, array{group: string, permissions: array<int, array{name: string}>}>
     */
    private function permissionGroups(): array
    {
        $known = Permission::query()->pluck('name')->all();

        return collect(PermissionCatalog::GROUPS)
            ->map(fn (array $permissions, string $group) => [
                'group' => $group,
                'permissions' => collect($permissions)
                    ->filter(fn (string $name) => in_array($name, $known, true))
                    ->map(fn (string $name) => ['name' => $name])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
