<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * What a personal access token may be allowed to do.
 *
 * A scope is not a permission. It is a *narrowing* of one: every scope names
 * the permissions its holder must already have, and the middleware requires
 * both. So a token is the intersection of two things — what it was issued for
 * and what its owner may do — and it shrinks on its own when the owner's role
 * is reduced. A token therefore can never be a way to exceed the person who
 * created it, which is the property that makes handing one to a script safe.
 *
 * The consequence worth stating out loud: revoking a role revokes every token
 * that leaned on it, without anyone having to remember the tokens exist.
 */
final class ApiScopes
{
    /**
     * scope => permissions the owner must hold for it to mean anything.
     *
     * @var array<string, array<int, string>>
     */
    public const SCOPES = [
        'tickets.read' => ['tickets.view', 'portal.submit'],
        'tickets.write' => ['tickets.create', 'tickets.update', 'tickets.transition'],
        // `portal.submit` is here on purpose. A requester replying to their
        // own ticket holds no `tickets.comment` permission — TicketPolicy lets
        // them through as a participant instead. Leaving it out would refuse
        // the most ordinary integration there is: a portal or chat bot posting
        // a customer's reply. The policy still makes the per-record call.
        'comments.write' => ['tickets.comment', 'portal.submit'],
        'queues.read' => ['tickets.view'],
        'assets.read' => ['assets.view'],
        'assets.write' => ['assets.manage'],
        'kb.read' => ['kb.view'],
        'webhooks.manage' => ['webhooks.manage'],
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::SCOPES);
    }

    public static function exists(string $scope): bool
    {
        return array_key_exists($scope, self::SCOPES);
    }

    /**
     * The permissions a scope leans on. Holding *any* of them is enough: the
     * per-record policy still runs afterwards, so this is the coarse filter
     * that keeps a requester's token off the agent endpoints, not the fine one
     * that decides which ticket they may read.
     *
     * @return array<int, string>
     */
    public static function permissionsFor(string $scope): array
    {
        return self::SCOPES[$scope] ?? [];
    }

    /**
     * Drop anything that is not a real scope. Used when a token is minted, so
     * a typo becomes a missing ability at issue time rather than a 403 that
     * nobody can explain three weeks later.
     *
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    public static function sanitise(array $scopes): array
    {
        return array_values(array_unique(array_filter(
            $scopes,
            static fn (string $scope): bool => self::exists($scope),
        )));
    }

    /**
     * The scopes this user could actually make use of. Offering them a
     * `assets.write` checkbox they cannot exercise produces a token that
     * silently 403s, so the UI only offers what will work.
     *
     * @return array<int, string>
     */
    public static function availableTo(User $user): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (string $scope): bool => $user->hasAnyPermission(...self::permissionsFor($scope)),
        ));
    }
}
