<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('organizations.manage', 'tickets.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->hasAnyPermission('organizations.manage', 'tickets.view')
            || $user->organization_id === $organization->getKey();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('organizations.manage');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organizations.manage');
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organizations.manage');
    }
}
