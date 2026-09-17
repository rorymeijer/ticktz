<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Who may manage accounts.
 *
 * Note the deliberate asymmetry: `users.manage` is enough to create and edit
 * accounts, but deactivating or deleting *yourself* is never allowed — that is
 * how an instance ends up with no administrator.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function view(User $user, User $subject): bool
    {
        return $user->hasPermission('users.manage') || $user->is($subject);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function update(User $user, User $subject): bool
    {
        return $user->hasPermission('users.manage');
    }

    public function delete(User $user, User $subject): bool
    {
        return $user->hasPermission('users.manage') && ! $user->is($subject);
    }

    public function deactivate(User $user, User $subject): bool
    {
        return $user->hasPermission('users.manage') && ! $user->is($subject);
    }

    /**
     * Role assignment is a separate, stronger permission than account
     * management: an operator who can reset passwords should not be able to
     * hand themselves the administrator role.
     */
    public function assignRoles(User $user, User $subject): bool
    {
        return $user->hasPermission('roles.manage');
    }

    public function impersonate(User $user, User $subject): bool
    {
        return $user->isSuperAdmin() && ! $user->is($subject) && ! $subject->isSuperAdmin();
    }
}
