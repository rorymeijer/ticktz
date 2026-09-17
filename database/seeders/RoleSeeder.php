<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Creates the three system roles. Re-running never overwrites permission
 * changes an administrator has made — only the role's identity is kept in
 * sync, and permissions are seeded once at creation time.
 */
class RoleSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, display_name: string, description: string, scope: string, is_super: bool, is_default: bool, position: int}>
     */
    private const ROLES = [
        [
            'name' => Role::ADMIN,
            'display_name' => 'Administrator',
            'description' => 'Full access to every part of the service desk, including configuration.',
            'scope' => 'admin',
            'is_super' => true,
            'is_default' => false,
            'position' => 10,
        ],
        [
            'name' => Role::AGENT,
            'display_name' => 'Agent',
            'description' => 'Works tickets: queues, assignment, replies and transitions.',
            'scope' => 'agent',
            'is_super' => false,
            'is_default' => false,
            'position' => 20,
        ],
        [
            'name' => Role::REQUESTER,
            'display_name' => 'Requester',
            'description' => 'Submits and follows requests through the customer portal.',
            'scope' => 'requester',
            'is_super' => false,
            'is_default' => true,
            'position' => 30,
        ],
    ];

    public function run(): void
    {
        $defaults = PermissionCatalog::defaultsForSystemRoles();

        foreach (self::ROLES as $attributes) {
            $role = Role::query()->where('name', $attributes['name'])->first();

            if ($role) {
                // Keep identity in sync but leave the operator's permission
                // choices alone.
                $role->fill([
                    'scope' => $attributes['scope'],
                    'is_super' => $attributes['is_super'],
                    'is_system' => true,
                    'position' => $attributes['position'],
                ])->save();

                continue;
            }

            $role = Role::query()->create($attributes + ['is_system' => true]);
            $role->syncPermissionNames($defaults[$attributes['name']] ?? []);
        }
    }
}
