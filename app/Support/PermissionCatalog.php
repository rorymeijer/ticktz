<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The complete permission vocabulary of Ticktz.
 *
 * Permissions are defined in code — not created through the UI — so that a
 * policy can reference a constant and a typo becomes a failing test rather
 * than a silently open endpoint. The seeder syncs this list into the
 * `permissions` table; removing an entry here removes it everywhere.
 */
final class PermissionCatalog
{
    /**
     * group => [permission, ...]
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'tickets' => [
            'tickets.view',          // see tickets in the agent console
            'tickets.view.all',      // ... beyond your own team's queues
            'tickets.create',
            'tickets.update',
            'tickets.assign',
            'tickets.transition',
            'tickets.comment',
            'tickets.comment.internal',
            'tickets.merge',
            'tickets.link',
            'tickets.delete',
            'tickets.export',
        ],
        'queues' => [
            'queues.manage',
        ],
        'portal' => [
            'portal.submit',
        ],
        'kb' => [
            'kb.view',
            'kb.view.internal',
            'kb.manage',
        ],
        'assets' => [
            'assets.view',
            'assets.manage',
            'assets.import',
        ],
        'approvals' => [
            'approvals.view',
            'approvals.decide',
            'approvals.manage',
        ],
        'sla' => [
            'sla.manage',
        ],
        'automation' => [
            'automation.manage',
        ],
        'reports' => [
            'reports.view',
            'reports.manage',
        ],
        'admin' => [
            'users.manage',
            'roles.manage',
            'teams.manage',
            'organizations.manage',
            'settings.manage',
            'email.manage',
            'directory.manage',
            'audit.view',
        ],
        'api' => [
            'api.tokens.manage',
            'webhooks.manage',
        ],
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function groupOf(string $permission): string
    {
        foreach (self::GROUPS as $group => $permissions) {
            if (in_array($permission, $permissions, true)) {
                return $group;
            }
        }

        return 'other';
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Default permission sets for the three system roles.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaultsForSystemRoles(): array
    {
        return [
            // The admin role is flagged `is_super`, so its permission list is
            // only informational — the gate short-circuits before consulting it.
            'admin' => self::all(),

            'agent' => [
                'tickets.view', 'tickets.create', 'tickets.update', 'tickets.assign',
                'tickets.transition', 'tickets.comment', 'tickets.comment.internal',
                'tickets.merge', 'tickets.link', 'tickets.export',
                'portal.submit',
                'kb.view', 'kb.view.internal',
                'assets.view',
                'approvals.view',
                'reports.view',
            ],

            'requester' => [
                'portal.submit',
                'kb.view',
            ],
        ];
    }
}
