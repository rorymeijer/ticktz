<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Queue;
use App\Models\User;

class QueuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('tickets.view');
    }

    public function view(User $user, Queue $queue): bool
    {
        if ($user->hasPermission('queues.manage')) {
            return true;
        }

        if (! $user->hasPermission('tickets.view')) {
            return false;
        }

        if ((int) $queue->owner_id === $user->getKey()) {
            return true;
        }

        if ($queue->team_id !== null) {
            return in_array($queue->team_id, $user->teamIds(), true);
        }

        return $queue->is_shared;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('queues.manage');
    }

    public function update(User $user, Queue $queue): bool
    {
        return $user->hasPermission('queues.manage') || (int) $queue->owner_id === $user->getKey();
    }

    public function delete(User $user, Queue $queue): bool
    {
        return $this->update($user, $queue);
    }
}
