import { describe, expect, it } from 'vitest';

import { agentNavigation, isActive } from '@/lib/navigation';
import type { User } from '@/types';

function user(permissions: string[]): User {
    return {
        id: 1,
        name: 'Test',
        email: 'test@example.org',
        locale: 'en',
        initials: 'T',
        avatar_color: '#000',
        is_admin: false,
        is_agent: true,
        is_requester: false,
        roles: [],
        permissions,
        team_ids: [],
        organization_id: null,
    };
}

const t = (key: string) => key;

describe('agentNavigation', () => {
    it('shows nothing to an anonymous visitor', () => {
        expect(agentNavigation(null, t)).toEqual([]);
    });

    it('always includes the dashboard for a signed-in user', () => {
        expect(agentNavigation(user([]), t).map((item) => item.key)).toEqual(['dashboard']);
    });

    it('adds the ticket destinations only with the ticket permission', () => {
        const keys = agentNavigation(user(['tickets.view']), t).map((item) => item.key);

        expect(keys).toContain('tickets');
        expect(keys).toContain('queues');
        expect(keys).not.toContain('admin');
    });

    it('hides the ticket destinations from an administrator without them', () => {
        const keys = agentNavigation(user(['users.manage']), t).map((item) => item.key);

        expect(keys).toContain('admin');
        expect(keys).not.toContain('tickets');
    });

    it('gives a wildcard holder every destination', () => {
        const keys = agentNavigation(user(['*']), t).map((item) => item.key);

        expect(keys).toEqual(expect.arrayContaining(['dashboard', 'tickets', 'queues', 'admin']));
    });

    it('shows administration to someone who may only manage users', () => {
        const keys = agentNavigation(user(['users.manage']), t).map((item) => item.key);

        expect(keys).toContain('admin');
        expect(keys).not.toContain('tickets');
    });
});

describe('isActive', () => {
    const item = { key: 'tickets', label: 'Tickets', href: '/agent/tickets', icon: (() => null) as never, match: ['/agent/tickets'] };

    it('matches the exact path', () => {
        expect(isActive(item, '/agent/tickets')).toBe(true);
    });

    it('matches a child path', () => {
        expect(isActive(item, '/agent/tickets/SUP-1042')).toBe(true);
    });

    it('does not match a sibling that merely shares a prefix', () => {
        expect(isActive(item, '/agent/ticketsettings')).toBe(false);
    });
});
