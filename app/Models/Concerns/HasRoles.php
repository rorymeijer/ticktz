<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Role and permission helpers for the User model.
 *
 * Permission resolution is memoised per request: a single page can call
 * `can()` dozens of times and must not issue a query each time.
 */
trait HasRoles
{
    /** @var array<int, string>|null */
    private ?array $resolvedPermissions = null;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string ...$names): bool
    {
        return $this->roles->whereIn('name', $names)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->is_super);
    }

    /**
     * Flat list of every permission granted by the user's roles. A super-admin
     * resolves to the single wildcard entry.
     *
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        if ($this->resolvedPermissions !== null) {
            return $this->resolvedPermissions;
        }

        if (! $this->is_active) {
            return $this->resolvedPermissions = [];
        }

        if ($this->isSuperAdmin()) {
            return $this->resolvedPermissions = ['*'];
        }

        $this->loadMissing('roles.permissions');

        return $this->resolvedPermissions = $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissionNames();

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assign roles by slug, replacing any current assignment.
     *
     * @param  array<int, string>  $names
     */
    public function syncRoleNames(array $names): void
    {
        $this->roles()->sync(Role::query()->whereIn('name', $names)->pluck('id')->all());
        $this->unsetRelation('roles');
        $this->resolvedPermissions = null;
    }

    public function assignRole(string $name): void
    {
        $role = Role::query()->where('name', $name)->first();

        if ($role && ! $this->roles()->whereKey($role->getKey())->exists()) {
            $this->roles()->attach($role);
            $this->unsetRelation('roles');
            $this->resolvedPermissions = null;
        }
    }

    /**
     * The widest shell the user may enter: admin > agent > requester.
     */
    public function scopeLevel(): string
    {
        /** @var Collection<int, Role> $roles */
        $roles = $this->roles;

        if ($this->isSuperAdmin() || $roles->contains('scope', 'admin')) {
            return 'admin';
        }

        if ($roles->contains('scope', 'agent')) {
            return 'agent';
        }

        return 'requester';
    }

    public function isAdmin(): bool
    {
        return $this->scopeLevel() === 'admin';
    }

    public function isAgent(): bool
    {
        return in_array($this->scopeLevel(), ['admin', 'agent'], true);
    }

    public function isRequester(): bool
    {
        return ! $this->isAgent();
    }
}
