import type { ComponentType, SVGProps } from 'react';

import {
    IconBook,
    IconCheckCircle,
    IconCog,
    IconDashboard,
    IconInbox,
    IconServer,
    IconTicket,
} from '@/Components/Icons';
import type { User } from '@/types';

export interface NavItem {
    key: string;
    label: string;
    href: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    /** Route prefixes that should light this item up. */
    match: string[];
}

type Translator = (key: string) => string;

/**
 * Builds the agent/admin sidebar. Visibility mirrors the server-side policies;
 * hiding a link is a convenience, never the access control itself.
 */
export function agentNavigation(user: User | null, t: Translator): NavItem[] {
    if (!user) return [];

    const can = (permission: string) => user.permissions.includes('*') || user.permissions.includes(permission);

    const items: NavItem[] = [
        { key: 'dashboard', label: t('nav.dashboard'), href: '/dashboard', icon: IconDashboard, match: ['/dashboard'] },
    ];

    // Destinations are added here as each phase ships its routes; an item
    // that has no backing route would be a dead link, which is worse than an
    // absent one.

    if (can('tickets.view')) {
        items.push(
            { key: 'tickets', label: t('nav.tickets'), href: '/agent/tickets', icon: IconTicket, match: ['/agent/tickets'] },
            { key: 'queues', label: t('nav.queues'), href: '/agent/queues', icon: IconInbox, match: ['/agent/queues'] },
        );
    }

    // Every agent gets the approvals inbox, permission or not: being asked to
    // approve something is not a permission, and an agent who was asked needs
    // somewhere to answer it.
    items.push({
        key: 'approvals',
        label: t('nav.approvals'),
        href: '/approvals',
        icon: IconCheckCircle,
        match: ['/approvals'],
    });

    if (can('assets.view')) {
        items.push({
            key: 'assets',
            label: t('nav.assets'),
            href: '/agent/assets',
            icon: IconServer,
            match: ['/agent/assets'],
        });
    }

    if (can('kb.view')) {
        items.push({ key: 'kb', label: t('nav.kb'), href: '/agent/kb', icon: IconBook, match: ['/agent/kb'] });
    }

    if (can('settings.manage') || can('users.manage')) {
        items.push({ key: 'admin', label: t('nav.admin'), href: '/admin', icon: IconCog, match: ['/admin'] });
    }

    return items;
}

export function isActive(item: NavItem, pathname: string): boolean {
    return item.match.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}
