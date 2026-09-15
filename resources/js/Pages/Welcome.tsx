import { Head, Link } from '@inertiajs/react';

import { BrandLockup } from '@/Components/Brand';
import { ButtonLink } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import type { SharedProps } from '@/types';

/**
 * Public landing page. Deliberately static and dependency-free so it renders
 * instantly on a cold self-hosted instance.
 */
export default function Welcome(_props: SharedProps) {
    const { t } = useTranslations();
    const { user } = useAuth();

    const features = [
        { title: t('portal.landing.feature_tickets_title'), body: t('portal.landing.feature_tickets_body') },
        { title: t('portal.landing.feature_sla_title'), body: t('portal.landing.feature_sla_body') },
        { title: t('portal.landing.feature_privacy_title'), body: t('portal.landing.feature_privacy_body') },
    ];

    return (
        <>
            <Head title={t('portal.landing.tagline')} />

            <div className="min-h-full bg-white">
                <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
                    <BrandLockup subtitle={t('portal.landing.tagline')} />
                    <nav className="flex items-center gap-2">
                        {user ? (
                            <ButtonLink href="/dashboard" size="sm">
                                {t('nav.dashboard')}
                            </ButtonLink>
                        ) : (
                            <ButtonLink href="/login" size="sm">
                                {t('portal.landing.cta_login')}
                            </ButtonLink>
                        )}
                    </nav>
                </header>

                <main id="main" className="mx-auto max-w-5xl px-6 pb-24 pt-10">
                    <h1 className="max-w-2xl text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                        {t('portal.welcome.title')}
                    </h1>
                    <p className="mt-4 max-w-2xl text-base leading-7 text-slate-600">{t('portal.landing.intro')}</p>

                    <div className="mt-8 flex flex-wrap gap-3">
                        <ButtonLink href="/portal" size="lg">
                            {t('portal.landing.cta_portal')}
                        </ButtonLink>
                        <Link
                            href="/health"
                            className="inline-flex h-11 items-center rounded-lg px-4 text-sm font-medium text-slate-600 hover:text-slate-900"
                        >
                            /health
                        </Link>
                    </div>

                    <dl className="mt-16 grid gap-6 sm:grid-cols-3">
                        {features.map((feature) => (
                            <div key={feature.title} className="rounded-xl border border-slate-200 bg-slate-50/60 p-5">
                                <dt className="text-sm font-semibold text-slate-900">{feature.title}</dt>
                                <dd className="mt-1.5 text-sm leading-6 text-slate-600">{feature.body}</dd>
                            </div>
                        ))}
                    </dl>
                </main>
            </div>
        </>
    );
}
