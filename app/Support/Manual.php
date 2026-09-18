<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The manual's table of contents.
 *
 * Two things are defined here and nowhere else: which chapters exist, and who
 * may read each one. The second is what makes this a *permission-aware*
 * manual — a reader is shown the parts of Ticktz they can actually reach, and
 * not a chapter on automation rules they have no way to open.
 *
 * `pages` is the other half, and the reason the manual and the `?` button
 * cannot drift apart: it names the Inertia page components a chapter explains.
 * The `?` on a screen asks which chapter covers the component it is sitting
 * in, so adding a screen without adding it here means the `?` says there is no
 * page for this yet — visibly, rather than silently opening the wrong chapter.
 *
 * The title and the opening line are not here on purpose. They are the first
 * heading and the first paragraph of the chapter's own Markdown, so there is
 * one copy of each and no way for a table of contents to promise something the
 * chapter does not say.
 */
final class Manual
{
    /**
     * slug => [permission, pages]
     *
     * `permission` is null when the chapter is for anybody with an account.
     * Where several are listed, holding any one of them is enough: a chapter
     * about approvals is for the person who decides them and the person who
     * asks for them, and they hold different permissions.
     *
     * @var array<string, array{permission: string|array<int, string>|null, pages: array<int, string>}>
     */
    public const CHAPTERS = [
        'getting-started' => [
            'permission' => null,
            'pages' => ['Dashboard', 'Profile/Edit', 'Welcome'],
        ],
        'raising-a-request' => [
            'permission' => 'portal.submit',
            'pages' => ['Portal/Index', 'Portal/RequestForm'],
        ],
        'following-your-requests' => [
            'permission' => 'portal.submit',
            'pages' => ['Portal/Requests/Index', 'Portal/Requests/Show', 'Portal/Equipment'],
        ],
        'the-agent-console' => [
            'permission' => 'tickets.view',
            'pages' => ['Agent/Tickets/Index', 'Agent/Queues/Index', 'Agent/Tickets/Create'],
        ],
        'working-a-ticket' => [
            'permission' => 'tickets.view',
            'pages' => ['Agent/Tickets/Show'],
        ],
        'service-levels' => [
            'permission' => 'tickets.view',
            'pages' => [],
        ],
        'approvals' => [
            'permission' => ['approvals.view', 'approvals.decide'],
            'pages' => ['Approvals/Index', 'Approvals/Token'],
        ],
        'knowledge-base' => [
            'permission' => ['kb.view', 'portal.submit'],
            'pages' => ['Agent/Kb/Index', 'Agent/Kb/Show', 'Portal/Kb/Index', 'Portal/Kb/Show'],
        ],
        'assets' => [
            'permission' => 'assets.view',
            'pages' => ['Agent/Assets/Index', 'Agent/Assets/Show'],
        ],
        'reports' => [
            'permission' => 'reports.view',
            'pages' => ['Reports/Index'],
        ],
        'admin-people-and-permissions' => [
            'permission' => ['users.manage', 'roles.manage', 'teams.manage', 'organizations.manage'],
            'pages' => [
                'Admin/Users/Index', 'Admin/Users/Form',
                'Admin/Roles/Index', 'Admin/Roles/Form',
                'Admin/Teams/Index', 'Admin/Teams/Form',
                'Admin/Organizations/Index', 'Admin/Organizations/Form',
                'Admin/Directories/Index',
            ],
        ],
        'admin-shaping-the-desk' => [
            'permission' => ['queues.manage', 'settings.manage'],
            'pages' => [
                'Admin/Queues/Index', 'Admin/Queues/Form',
                'Admin/RequestTypes/Index', 'Admin/RequestTypes/Form',
                'Admin/Workflows/Index', 'Admin/Workflows/Form',
                'Admin/ServiceDesk/Index', 'Admin/CustomFields/Index',
            ],
        ],
        'admin-service-levels' => [
            'permission' => 'sla.manage',
            'pages' => ['Admin/Sla/Index'],
        ],
        'admin-automation' => [
            'permission' => 'automation.manage',
            'pages' => ['Admin/Automation/Index', 'Admin/Webhooks/Index'],
        ],
        'admin-reply-templates' => [
            'permission' => 'templates.manage',
            'pages' => ['Admin/ReplyTemplates/Index'],
        ],
        'admin-approvals' => [
            'permission' => 'approvals.manage',
            'pages' => ['Admin/Approvals/Index'],
        ],
        'admin-email' => [
            'permission' => 'email.manage',
            'pages' => ['Admin/Email/Index'],
        ],
        'admin-knowledge-base' => [
            'permission' => 'kb.manage',
            'pages' => ['Admin/Kb/Index', 'Admin/Kb/Edit'],
        ],
        'admin-assets' => [
            'permission' => 'assets.manage',
            'pages' => ['Admin/AssetTypes/Index', 'Admin/Assets/Import'],
        ],
        'admin-running-the-system' => [
            'permission' => ['settings.manage', 'updates.manage', 'audit.view'],
            'pages' => ['Admin/Index', 'Admin/Settings/Index', 'Admin/Updates/Index', 'Admin/AuditLog/Index'],
        ],
        'the-api' => [
            'permission' => 'api.tokens.manage',
            'pages' => ['Settings/ApiTokens'],
        ],
    ];

    /**
     * Every page component some chapter explains.
     *
     * Shared with the front end so a `?` can decide whether to exist. A button
     * that opens and then admits there is nothing written is worse than no
     * button.
     *
     * @return array<int, string>
     */
    public static function coveredPages(): array
    {
        $pages = [];

        foreach (self::CHAPTERS as $chapter) {
            foreach ($chapter['pages'] as $page) {
                $pages[] = $page;
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * Which chapter explains this page, if any.
     *
     * Pages with no chapter are real and fine — a password reset screen needs
     * no manual — so this answers null rather than guessing at a near match.
     * A `?` that opens approximately the right chapter is worse than one that
     * admits there is nothing written.
     */
    public static function chapterForPage(string $component): ?string
    {
        foreach (self::CHAPTERS as $slug => $chapter) {
            if (in_array($component, $chapter['pages'], true)) {
                return $slug;
            }
        }

        return null;
    }
}
