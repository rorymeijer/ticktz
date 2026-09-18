import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types';

interface AdminNavItem {
    key: string;
    href: string;
    label: string;
    permission: string;
    section: 'identity' | 'service_desk' | 'system';
}

/**
 * Administration shell: the agent chrome plus a secondary navigation column.
 * Items are filtered by permission so an operator who may only manage teams
 * does not see a list of doors they cannot open.
 */
export default function AdminLayout({
    title,
    children,
    actions,
}: {
    title: string;
    children: ReactNode;
    actions?: ReactNode;
}) {
    const { t } = useTranslations();
    const { can } = useAuth();
    const { ziggy } = usePage<SharedProps>().props;
    const pathname = new URL(ziggy.location).pathname;

    const items: AdminNavItem[] = ([
        { key: 'overview', href: '/admin', label: t('admin.nav.overview'), permission: 'users.manage', section: 'identity' },
        { key: 'users', href: '/admin/users', label: t('admin.nav.users'), permission: 'users.manage', section: 'identity' },
        { key: 'roles', href: '/admin/roles', label: t('admin.nav.roles'), permission: 'roles.manage', section: 'identity' },
        { key: 'teams', href: '/admin/teams', label: t('admin.nav.teams'), permission: 'teams.manage', section: 'identity' },
        {
            key: 'organizations',
            href: '/admin/organizations',
            label: t('admin.nav.organizations'),
            permission: 'organizations.manage',
            section: 'identity',
        },
        {
            key: 'service-desk',
            href: '/admin/service-desk',
            label: t('admin.nav.service_desk'),
            permission: 'settings.manage',
            section: 'service_desk',
        },
        {
            key: 'workflows',
            href: '/admin/workflows',
            label: t('admin.nav.workflows'),
            permission: 'settings.manage',
            section: 'service_desk',
        },
        {
            key: 'queues',
            href: '/admin/queues',
            label: t('admin.nav.queues'),
            permission: 'queues.manage',
            section: 'service_desk',
        },
        {
            key: 'request-types',
            href: '/admin/request-types',
            label: t('admin.nav.request_types'),
            permission: 'settings.manage',
            section: 'service_desk',
        },
        {
            key: 'custom-fields',
            href: '/admin/custom-fields',
            label: t('admin.nav.custom_fields'),
            permission: 'settings.manage',
            section: 'service_desk',
        },
        {
            key: 'automation',
            href: '/admin/automation',
            label: t('admin.nav.automation'),
            permission: 'automation.manage',
            section: 'service_desk',
        },
        {
            key: 'reply-templates',
            href: '/admin/reply-templates',
            label: t('admin.nav.reply_templates'),
            permission: 'templates.manage',
            section: 'service_desk',
        },
        {
            key: 'asset-types',
            href: '/admin/asset-types',
            label: t('admin.nav.asset_types'),
            permission: 'assets.manage',
            section: 'service_desk',
        },
        {
            key: 'approvals',
            href: '/admin/approvals',
            label: t('admin.nav.approvals'),
            permission: 'approvals.manage',
            section: 'service_desk',
        },
        {
            key: 'kb',
            href: '/admin/kb',
            label: t('admin.nav.kb'),
            permission: 'kb.manage',
            section: 'service_desk',
        },
        {
            key: 'sla',
            href: '/admin/sla',
            label: t('admin.nav.sla'),
            permission: 'sla.manage',
            section: 'service_desk',
        },
        {
            key: 'email',
            href: '/admin/email',
            label: t('admin.nav.email'),
            permission: 'email.manage',
            section: 'system',
        },
        {
            key: 'directories',
            href: '/admin/directories',
            label: t('admin.nav.directories'),
            permission: 'directory.manage',
            section: 'system',
        },
        {
            key: 'webhooks',
            href: '/admin/webhooks',
            label: t('admin.nav.webhooks'),
            permission: 'webhooks.manage',
            section: 'system',
        },
        { key: 'settings', href: '/admin/settings', label: t('admin.nav.settings'), permission: 'settings.manage', section: 'system' },
        { key: 'audit', href: '/admin/audit-log', label: t('admin.nav.audit'), permission: 'audit.view', section: 'system' },
        { key: 'updates', href: '/admin/updates', label: t('admin.nav.updates'), permission: 'updates.manage', section: 'system' },
    ] satisfies AdminNavItem[]).filter((item) => can(item.permission));

    const sections: AdminNavItem['section'][] = ['identity', 'service_desk', 'system'];

    const isCurrent = (href: string) => (href === '/admin' ? pathname === '/admin' : pathname.startsWith(href));

    return (
        <AppLayout title={title} header={t('admin.title')} actions={actions}>
            <div className="flex flex-col gap-6 lg:flex-row">
                <nav className="lg:w-52 lg:shrink-0" aria-label={t('admin.title')}>
                    <div className="flex gap-1 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
                        {sections.map((section) => {
                            const sectionItems = items.filter((item) => item.section === section);

                            if (sectionItems.length === 0) return null;

                            return (
                                <div key={section} className="contents lg:block">
                                    <p className="hidden px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wide text-slate-500 first:pt-0 lg:block">
                                        {t(`admin.sections.${section}`)}
                                    </p>
                                    {sectionItems.map((item) => (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            aria-current={isCurrent(item.href) ? 'page' : undefined}
                                            className={cn(
                                                'whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors lg:block',
                                                isCurrent(item.href)
                                                    ? 'bg-white text-brand-700 shadow-card lg:bg-brand-50 lg:shadow-none'
                                                    : 'text-slate-600 hover:bg-white hover:text-slate-900 lg:hover:bg-slate-100',
                                            )}
                                        >
                                            {item.label}
                                        </Link>
                                    ))}
                                </div>
                            );
                        })}
                    </div>
                </nav>

                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </AppLayout>
    );
}
