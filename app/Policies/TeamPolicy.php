<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission('teams.manage', 'tickets.view');
    }

    public function view(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.manage')
            || in_array($team->getKey(), $user->teamIds(), true);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.manage');
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasPermission('teams.manage');
    }
}
