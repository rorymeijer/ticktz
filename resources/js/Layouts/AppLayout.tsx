import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

import { BrandLockup } from '@/Components/Brand';
import { IconChevronDown, IconMenu, IconX } from '@/Components/Icons';
import { LocaleSwitcher } from '@/Components/Nav/LocaleSwitcher';
import { Avatar, Dropdown, DropdownDivider, DropdownLink, FlashMessages } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { agentNavigation, isActive } from '@/lib/navigation';
import type { SharedProps } from '@/types';

/**
 * The authenticated agent/admin shell: a persistent sidebar, a top bar with
 * the user menu, and a main region that pages fill.
 */
export default function AppLayout({
    title,
    header,
    actions,
    children,
    fullWidth = false,
}: {
    title: string;
    header?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    fullWidth?: boolean;
}) {
    const { t } = useTranslations();
    const { user } = useAuth();
    const { app, ziggy } = usePage<SharedProps>().props;
    const [mobileOpen, setMobileOpen] = useState(false);

    const pathname = new URL(ziggy.location).pathname;
    const items = agentNavigation(user, t);

    useEffect(() => {
        setMobileOpen(false);
    }, [pathname]);

    const sidebar = (
        <nav className="flex h-full flex-col gap-1 p-3" aria-label={t('nav.agent_console')}>
            {items.map((item) => {
                const active = isActive(item, pathname);
                const Icon = item.icon;

                return (
                    <Link
                        key={item.key}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            active
                                ? 'bg-brand-50 text-brand-700'
                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                        )}
                    >
                        <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-brand-600' : 'text-slate-400')} />
                        <span className="truncate">{item.label}</span>
                    </Link>
                );
            })}

            <div className="mt-auto px-3 pt-4 text-[11px] text-slate-400">
                {app.name} {app.version}
            </div>
        </nav>
    );

    return (
        <>
            <Head title={title} />
            <FlashMessages />

            <a href="#main" className="ticktz-skip-link">
                {t('nav.skip_to_content')}
            </a>

            <div className="flex min-h-full bg-slate-50">
                {/* Desktop sidebar */}
                <aside className="hidden w-60 shrink-0 border-r border-slate-200 bg-white lg:flex lg:flex-col">
                    <div className="flex h-14 items-center border-b border-slate-200 px-4">
                        <Link href="/dashboard">
                            <BrandLockup name={app.name} />
                        </Link>
                    </div>
                    {sidebar}
                </aside>

                {/* Mobile drawer */}
                {mobileOpen ? (
                    <div className="fixed inset-0 z-40 lg:hidden">
                        <button
                            type="button"
                            aria-label={t('nav.close_menu')}
                            className="absolute inset-0 bg-slate-900/40"
                            onClick={() => setMobileOpen(false)}
                        />
                        <div className="absolute inset-y-0 left-0 flex w-64 flex-col bg-white shadow-popover">
                            <div className="flex h-14 items-center justify-between border-b border-slate-200 px-4">
                                <BrandLockup name={app.name} />
                                <button
                                    type="button"
                                    onClick={() => setMobileOpen(false)}
                                    aria-label={t('nav.close_menu')}
                                    className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100"
                                >
                                    <IconX />
                                </button>
                            </div>
                            {sidebar}
                        </div>
                    </div>
                ) : null}

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur">
                        <button
                            type="button"
                            onClick={() => setMobileOpen(true)}
                            aria-label={t('nav.open_menu')}
                            className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden"
                        >
                            <IconMenu />
                        </button>

                        <div className="min-w-0 flex-1">
                            <h1 className="truncate text-sm font-semibold text-slate-900">{header ?? title}</h1>
                        </div>

                        <div className="flex items-center gap-1.5">
                            {actions}
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
                                    <DropdownLink href="/portal">{t('nav.portal')}</DropdownLink>
                                    <DropdownDivider />
                                    <DropdownLink href="/logout" method="post" as="button" danger>
                                        {t('nav.log_out')}
                                    </DropdownLink>
                                </Dropdown>
                            ) : null}
                        </div>
                    </header>

                    <main id="main" className="flex-1">
                        <div className={cn('px-4 py-6 sm:px-6', fullWidth ? 'w-full' : 'mx-auto max-w-7xl')}>
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </>
    );
}
