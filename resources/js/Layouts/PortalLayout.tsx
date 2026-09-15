import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { BrandLockup } from '@/Components/Brand';
import { LocaleSwitcher } from '@/Components/Nav/LocaleSwitcher';
import { Avatar, Dropdown, DropdownDivider, DropdownLink, FlashMessages } from '@/Components/UI';
import { IconChevronDown } from '@/Components/Icons';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types';

/**
 * The customer-facing shell. Deliberately different chrome from the agent
 * console: a wide top bar, no sidebar, and only the handful of destinations a
 * requester needs.
 */
export default function PortalLayout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const { t } = useTranslations();
    const { user } = useAuth();
    const { app, ziggy, approvals_waiting: approvalsWaiting } = usePage<SharedProps>().props;
    const pathname = new URL(ziggy.location).pathname;

    // "/portal" must not stay highlighted while you are on "/portal/requests".
    const isCurrent = (href: string) =>
        href === '/portal' ? pathname === '/portal' : pathname.startsWith(href);

    // Extended per phase as the portal grows; see docs/roadmap.md.
    const links = [
        { href: '/portal', label: t('portal.nav.browse') },
        { href: '/portal/kb', label: t('kb.portal_title') },
        { href: '/portal/requests', label: t('portal.nav.my_requests') },
        { href: '/portal/equipment', label: t('portal.nav.equipment') },
    ];

    // Only when something is actually waiting. Most requesters are never asked
    // to approve anything, and a permanent link to an empty inbox is clutter
    // on the one screen that has to stay obvious.
    if (approvalsWaiting > 0) {
        links.push({ href: '/approvals', label: `${t('nav.approvals')} (${approvalsWaiting})` });
    }

    return (
        <>
            <Head title={title} />
            <FlashMessages />

            <a href="#main" className="ticktz-skip-link">
                {t('nav.skip_to_content')}
            </a>

            <div className="min-h-screen bg-slate-50">
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto flex h-14 max-w-5xl items-center gap-4 px-4 sm:px-6">
                        <Link href="/portal">
                            <BrandLockup name={app.name} />
                        </Link>

                        <nav className="hidden flex-1 items-center gap-1 sm:flex" aria-label={t('nav.portal')}>
                            {links.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    aria-current={isCurrent(link.href) ? 'page' : undefined}
                                    className={cn(
                                        'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                        isCurrent(link.href)
                                            ? 'bg-brand-50 text-brand-700'
                                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                                    )}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </nav>

                        <div className="ml-auto flex items-center gap-1.5">
                            <LocaleSwitcher />
                            {user ? (
                                <Dropdown
                                    trigger={
                                        <button
                                            type="button"
                                            className="flex items-center gap-1.5 rounded-lg p-1 hover:bg-slate-100"
                                        >
                                            <Avatar
                                                name={user.name}
                                                initials={user.initials}
                                                color={user.avatar_color}
                                                size="sm"
                                            />
                                            <IconChevronDown className="h-3.5 w-3.5 text-slate-400" />
                                        </button>
                                    }
                                >
                                    <div className="px-3 py-2">
                                        <p className="truncate text-sm font-medium text-slate-900">{user.name}</p>
                                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                                    </div>
                                    <DropdownDivider />
                                    <DropdownLink href="/profile">{t('nav.profile')}</DropdownLink>
                                    {user.is_agent ? (
                                        <DropdownLink href="/dashboard">{t('nav.agent_console')}</DropdownLink>
                                    ) : null}
                                    <DropdownDivider />
                                    <DropdownLink href="/logout" method="post" as="button" danger>
                                        {t('nav.log_out')}
                                    </DropdownLink>
                                </Dropdown>
                            ) : (
                                <Link
                                    href="/login"
                                    className="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
                                >
                                    {t('nav.log_in')}
                                </Link>
                            )}
                        </div>
                    </div>
                </header>

                <main id="main" className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
                    {children}
                </main>
            </div>
        </>
    );
}
