import { Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { LineChart } from '@/Components/Charts/LineChart';
import { Legend } from '@/Components/Charts/Primitives';
import { SERIES } from '@/Components/Charts/tokens';
import { PriorityBadge, StatusBadge } from '@/Components/Tickets/Badges';
import { Card, CardBody, CardHeader, EmptyState } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { relativeTime } from '@/lib/datetime';
import type { TicketListItem } from '@/types/tickets';

interface Card {
    key: string;
    count: number;
    href: string;
    tone: 'default' | 'warning' | 'danger';
}

/**
 * What needs this person's attention today.
 *
 * Every figure is a link to a list they can act on — a dashboard number with
 * nowhere to click is a number people learn to stop reading.
 */
export default function Dashboard({
    cards,
    recent,
    trend,
}: {
    cards: Card[];
    recent: TicketListItem[];
    trend: { date: string; created: number; resolved: number }[] | null;
}) {
    const { t } = useTranslations();
    const { user } = useAuth();

    return (
        <AppLayout title={t('dashboard.title')}>
            <div className="mb-6">
                <h2 className="text-xl font-semibold tracking-tight text-slate-900">
                    {t('dashboard.greeting', { name: user?.name ?? '' })}
                </h2>
                <p className="mt-1 text-sm text-slate-500">{t('dashboard.subtitle')}</p>
            </div>

            {cards.length > 0 ? (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((card) => (
                        <Link
                            key={card.key}
                            href={card.href}
                            className="group rounded-xl border border-slate-200 bg-white p-4 shadow-card transition-colors hover:border-brand-300"
                        >
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {t(`dashboard.cards.${card.key}`)}
                            </p>
                            <p
                                className={cn(
                                    'mt-1 text-2xl font-semibold tabular-nums tracking-tight',
                                    card.count === 0
                                        ? 'text-slate-300'
                                        : card.tone === 'danger'
                                          ? 'text-red-700'
                                          : card.tone === 'warning'
                                            ? 'text-amber-700'
                                            : 'text-slate-900',
                                )}
                            >
                                {card.count}
                            </p>
                        </Link>
                    ))}
                </div>
            ) : null}

            {trend ? (
                <Card className="mt-5">
                    <CardHeader title={t('reports.volume.per_day')} />
                    <CardBody>
                        <Legend
                            items={[
                                { label: t('reports.volume.created'), color: SERIES[0] },
                                { label: t('reports.volume.resolved'), color: SERIES[1] },
                            ]}
                        />
                        <div className="mt-3">
                            <LineChart
                                height={160}
                                labels={trend.map((day) => day.date)}
                                series={[
                                    {
                                        key: 'created',
                                        label: t('reports.volume.created'),
                                        color: SERIES[0],
                                        values: trend.map((day) => day.created),
                                    },
                                    {
                                        key: 'resolved',
                                        label: t('reports.volume.resolved'),
                                        color: SERIES[1],
                                        values: trend.map((day) => day.resolved),
                                    },
                                ]}
                                format={(value) => String(Math.round(value))}
                            />
                        </div>
                    </CardBody>
                </Card>
            ) : null}

            <Card className="mt-5">
                <CardHeader title={t('dashboard.recent')} />
                {recent.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {recent.map((ticket) => (
                            <li key={ticket.key}>
                                <Link
                                    href={`/agent/tickets/${ticket.key}`}
                                    className="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-slate-50"
                                >
                                    <span className="font-mono text-xs font-semibold text-brand-600">{ticket.key}</span>
                                    <span className="min-w-0 flex-1 truncate text-sm text-slate-800">
                                        {ticket.subject}
                                    </span>
                                    <PriorityBadge priority={ticket.priority} />
                                    <StatusBadge status={ticket.status} />
                                    <span className="text-xs text-slate-500">
                                        {relativeTime(ticket.last_activity_at, t)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <CardBody>
                        <EmptyState title={t('dashboard.no_data')} />
                    </CardBody>
                )}
            </Card>
        </AppLayout>
    );
}
