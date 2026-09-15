import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { BrandLockup } from '@/Components/Brand';
import { LocaleSwitcher } from '@/Components/Nav/LocaleSwitcher';
import { FlashMessages } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * Centred card shell used by every unauthenticated screen (login, password
 * reset, e-mail verification).
 */
export default function AuthLayout({
    title,
    description,
    children,
    footer,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
}) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={title} />
            <FlashMessages />

            <div className="flex min-h-screen flex-col bg-slate-100">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <Link href="/" aria-label={t('nav.dashboard')}>
                        <BrandLockup subtitle={t('portal.landing.tagline')} />
                    </Link>
                    <LocaleSwitcher />
                </header>

                <main id="main" className="flex flex-1 items-start justify-center px-6 pb-16 pt-4">
                    <div className="w-full max-w-md">
                        <div className="rounded-xl border border-slate-200 bg-white p-7 shadow-card">
                            <h1 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h1>
                            {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
                            <div className="mt-6">{children}</div>
                        </div>
                        {footer ? <div className="mt-4 text-center text-sm text-slate-500">{footer}</div> : null}
                    </div>
                </main>
            </div>
        </>
    );
}
