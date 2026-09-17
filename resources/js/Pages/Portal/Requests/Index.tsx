import { Link, router } from '@inertiajs/react';

import PortalLayout from '@/Layouts/PortalLayout';
import { StatusBadge } from '@/Components/Tickets/Badges';
import { Avatar, ButtonLink, Card, EmptyState, PageHeader, Pagination } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { formatDate, relativeTime } from '@/lib/datetime';
import type { Paginated } from '@/types';
import type { StatusSummary, UserSummary } from '@/types/tickets';

interface RequestRow {
    key: string;
    subject: string;
    status: StatusSummary | null;
    request_type: string | null;
    requester: UserSummary | null;
    is_mine: boolean;
    created_at: string | null;
    last_activity_at: string | null;
}

export default function PortalRequestsIndex({
    requests,
    filters,
}: {
    requests: Paginated<RequestRow>;
    filters: { status: string | null };
}) {
    const { t, locale } = useTranslations();

    const tabs = [
        { value: null, label: t('portal.requests.filters.all') },
        { value: 'open', label: t('portal.requests.filters.open') },
        { value: 'closed', label: t('portal.requests.filters.closed') },
    ];

    return (
        <PortalLayout title={t('portal.requests.title')}>
            <PageHeader
                title={t('portal.requests.title')}
                description={t('portal.requests.subtitle')}
                actions={<ButtonLink href="/portal">{t('portal.nav.new_request')}</ButtonLink>}
            />

            <div className="mb-4 flex gap-1.5">
                {tabs.map((tab) => (
                    <button
                        key={tab.label}
                        type="button"
                        aria-pressed={(filters.status ?? null) === tab.value}
                        onClick={() =>
                            router.get('/portal/requests', tab.value ? { status: tab.value } : {}, {
                                preserveState: false,
                            })
                        }
                        className={cn(
                            'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                            (filters.status ?? null) === tab.value
                                ? 'bg-brand-600 text-white'
                                : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:text-slate-900',
                        )}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>

            <Card>
                {requests.data.length === 0 ? (
                    <EmptyState
                        title={t('portal.requests.empty')}
                        action={<ButtonLink href="/portal">{t('portal.requests.empty_action')}</ButtonLink>}
                    />
                ) : (
                    <>
                        <ul className="divide-y divide-slate-100">
                            {requests.data.map((request) => (
                                <li key={request.key}>
                                    <Link
                                        href={`/portal/requests/${request.key}`}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 py-3.5 hover:bg-slate-50"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-baseline gap-2">
                                                <span className="font-mono text-xs font-semibold text-brand-600">
                                                    {request.key}
                                                </span>
                                                <span className="min-w-0 truncate font-medium text-slate-900">
                                                    {request.subject}
                                                </span>
                                            </div>
                                            <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                                {request.request_type ? <span>{request.request_type}</span> : null}
                                                <span>{formatDate(request.created_at, locale)}</span>
                                                {!request.is_mine && request.requester ? (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Avatar
                                                            name={request.requester.name}
                                                            initials={request.requester.initials}
                                                            color={request.requester.avatar_color}
                                                            size="xs"
                                                        />
                                                        {t('portal.requests.submitted_by', {
                                                            name: request.requester.name,
                                                        })}
                                                    </span>
                                                ) : null}
                                            </p>
                                        </div>

                                        <StatusBadge status={request.status} />

                                        <span className="w-24 text-right text-xs text-slate-500">
                                            {relativeTime(request.last_activity_at, t)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination page={requests} />
                    </>
                )}
            </Card>
        </PortalLayout>
    );
}
