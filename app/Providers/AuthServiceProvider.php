<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Organization;
use App\Models\Queue;
use App\Models\Role;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Policies\CommentPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\QueuePolicy;
use App\Policies\RolePolicy;
use App\Policies\TeamPolicy;
use App\Policies\TicketPolicy;
use App\Policies\UserPolicy;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires RBAC into Laravel's authorisation layer.
 *
 * Every permission in the catalogue becomes a gate of the same name, so a
 * controller can write `$this->authorize('users.manage')` and a Blade/Inertia
 * check reads identically. Policies handle the per-record decisions on top.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Abilities that keep consulting the policy even for a super-admin. These
     * are the ones that could otherwise leave an instance with no usable
     * administrator account.
     *
     * @var array<int, string>
     */
    private const SELF_PROTECTED = ['delete', 'deactivate', 'impersonate'];

    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Team::class => TeamPolicy::class,
        Organization::class => OrganizationPolicy::class,
        Ticket::class => TicketPolicy::class,
        Comment::class => CommentPolicy::class,
        Queue::class => QueuePolicy::class,
        Attachment::class => AttachmentPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // A super-admin passes every check, with one exception: the guards
        // that stop an administrator locking themselves out must keep running,
        // so we fall through to the policy when the subject is the actor.
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if (! $user->is_active) {
                return false;
            }

            if (! $user->isSuperAdmin()) {
                return null;
            }

            $subject = $arguments[0] ?? null;

            if ($subject instanceof User && $user->is($subject) && in_array($ability, self::SELF_PROTECTED, true)) {
                return null;
            }

            return true;
        });

        foreach (PermissionCatalog::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
