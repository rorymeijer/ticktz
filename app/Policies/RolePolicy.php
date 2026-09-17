<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('roles.manage');
    }

    /**
     * System roles keep their name and scope but may be re-permissioned, so
     * that an organisation can decide what "agent" means for them.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermission('roles.manage') && ! $role->isProtected();
    }
}
