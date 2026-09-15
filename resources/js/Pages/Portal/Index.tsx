import { Link } from '@inertiajs/react';

import PortalLayout from '@/Layouts/PortalLayout';
import { StatusBadge } from '@/Components/Tickets/Badges';
import { IconChevronRight, IconSearch } from '@/Components/Icons';
import { Card, CardBody, CardHeader, EmptyState, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';
import type { StatusSummary } from '@/types/tickets';
import { useMemo, useState } from 'react';

interface RequestTypeCard {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon: string | null;
}

interface CategoryCard {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon: string | null;
    color: string;
    request_types: RequestTypeCard[];
}

interface RecentRequest {
    key: string;
    subject: string;
    status: StatusSummary | null;
    last_activity_at: string | null;
}

export default function PortalIndex({
    branding,
    categories,
    uncategorised,
    recentRequests,
}: {
    branding: { title: string | null; welcome_message: string | null; support_email: string | null };
    categories: CategoryCard[];
    uncategorised: RequestTypeCard[];
    recentRequests: RecentRequest[];
}) {
    const { t } = useTranslations();
    const [search, setSearch] = useState('');

    // Client-side filtering is right here: the catalogue is a handful of items
    // and a round trip per keystroke would feel worse than it reads.
    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();

        const matches = (type: RequestTypeCard) =>
            term === '' ||
            type.name.toLowerCase().includes(term) ||
            (type.description ?? '').toLowerCase().includes(term);

        return {
            categories: categories
                .map((category) => ({ ...category, request_types: category.request_types.filter(matches) }))
                .filter((category) => category.request_types.length > 0),
            uncategorised: uncategorised.filter(matches),
        };
    }, [categories, uncategorised, search]);

    const hasAnything = filtered.categories.length > 0 || filtered.uncategorised.length > 0;

    return (
        <PortalLayout title={branding.title ?? t('nav.portal')}>
            <div className="mb-8">
                <h2 className="text-2xl font-semibold tracking-tight text-slate-900">{t('portal.welcome.title')}</h2>
                <p className="mt-1.5 text-sm text-slate-600">
                    {branding.welcome_message || t('portal.welcome.subtitle')}
                </p>

                <div className="relative mt-5 max-w-xl">
                    <IconSearch className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <TextInput
                        type="search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder={t('portal.welcome.search_placeholder')}
                        aria-label={t('common.actions.search')}
                        className="h-11 pl-10"
                    />
                </div>
            </div>

            {hasAnything ? (
                <div className="space-y-8">
                    {filtered.categories.map((category) => (
                        <section key={category.id} aria-labelledby={`category-${category.id}`}>
                            <div className="mb-3 flex items-baseline gap-2">
                                <h3
                                    id={`category-${category.id}`}
                                    className="text-sm font-semibold uppercase tracking-wide text-slate-500"
                                >
                                    {category.name}
                                </h3>
                                {category.description ? (
                                    <p className="text-xs text-slate-400">{category.description}</p>
                                ) : null}
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {category.request_types.map((type) => (
                                    <RequestTypeTile key={type.id} type={type} color={category.color} />
                                ))}
                            </div>
                        </section>
                    ))}

                    {filtered.uncategorised.length > 0 ? (
                        <section aria-labelledby="uncategorised">
                            <h3
                                id="uncategorised"
                                className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500"
                            >
                                {t('portal.home.uncategorised')}
                            </h3>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {filtered.uncategorised.map((type) => (
                                    <RequestTypeTile key={type.id} type={type} color="#4f46e5" />
                                ))}
                            </div>
                        </section>
                    ) : null}
                </div>
            ) : (
                <Card>
                    <EmptyState
                        title={search ? t('common.empty.title') : t('portal.home.no_request_types')}
                        description={search ? t('common.empty.description') : undefined}
                    />
                </Card>
            )}

            {recentRequests.length > 0 ? (
                <Card className="mt-8">
                    <CardHeader
                        title={t('portal.home.recent')}
                        actions={
                            <Link
                                href="/portal/requests"
                                className="text-xs font-medium text-brand-600 hover:text-brand-700"
                            >
                                {t('portal.home.view_all')}
                            </Link>
                        }
                    />
                    <ul className="divide-y divide-slate-100">
                        {recentRequests.map((request) => (
                            <li key={request.key}>
                                <Link
                                    href={`/portal/requests/${request.key}`}
                                    className="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-slate-50"
                                >
                                    <span className="font-mono text-xs font-semibold text-brand-600">{request.key}</span>
                                    <span className="min-w-0 flex-1 truncate text-sm text-slate-800">
                                        {request.subject}
                                    </span>
                                    <StatusBadge status={request.status} />
                                    <span className="text-xs text-slate-400">
                                        {relativeTime(request.last_activity_at, t)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Card>
            ) : null}

            {branding.support_email ? (
                <Card className="mt-6">
                    <CardBody className="text-sm text-slate-600">
                        {t('portal.welcome.subtitle')}{' '}
                        <a
                            href={`mailto:${branding.support_email}`}
                            className="font-medium text-brand-600 hover:text-brand-700"
                        >
                            {branding.support_email}
                        </a>
                    </CardBody>
                </Card>
            ) : null}
        </PortalLayout>
    );
}

function RequestTypeTile({ type, color }: { type: RequestTypeCard; color: string }) {
    return (
        <Link
            href={`/portal/new/${type.slug}`}
            className="group flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-card transition-colors hover:border-brand-300"
        >
            <span
                aria-hidden="true"
                className="mt-0.5 h-8 w-1 shrink-0 rounded-full"
                style={{ backgroundColor: color }}
            />
            <span className="min-w-0 flex-1">
                <span className="block font-medium text-slate-900 group-hover:text-brand-700">{type.name}</span>
                {type.description ? (
                    <span className="mt-0.5 block text-sm leading-5 text-slate-500">{type.description}</span>
                ) : null}
            </span>
            <IconChevronRight className="mt-1 h-4 w-4 shrink-0 text-slate-300 group-hover:text-brand-500" />
        </Link>
    );
}
