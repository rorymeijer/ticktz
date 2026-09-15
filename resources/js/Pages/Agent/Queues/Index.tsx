import { Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { IconInbox } from '@/Components/Icons';
import { ButtonLink, Card, EmptyState, PageHeader } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { QueueSummary } from '@/types/tickets';

export default function QueuesIndex({
    queues,
    canManage,
}: {
    queues: QueueSummary[];
    canManage: boolean;
}) {
    const { t } = useTranslations();

    return (
        <AppLayout
            title={t('tickets.queues.title')}
            actions={
                canManage ? (
                    <ButtonLink href="/admin/queues" variant="secondary" size="sm">
                        {t('common.actions.edit')}
                    </ButtonLink>
                ) : null
            }
        >
            <PageHeader title={t('tickets.queues.title')} description={t('tickets.queues.subtitle')} />

            {queues.length === 0 ? (
                <Card>
                    <EmptyState title={t('tickets.queues.empty')} icon={<IconInbox className="h-8 w-8" />} />
                </Card>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {queues.map((queue) => (
                        <Link
                            key={queue.id}
                            href={`/agent/tickets?queue_slug=${queue.slug}`}
                            className="group rounded-xl border border-slate-200 bg-white p-4 shadow-card transition-colors hover:border-brand-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-slate-900 group-hover:text-brand-700">
                                        {queue.name}
                                    </p>
                                    {queue.team ? (
                                        <p className="mt-0.5 text-xs text-slate-500">{queue.team.name}</p>
                                    ) : null}
                                </div>
                                <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-sm font-semibold tabular-nums text-slate-700">
                                    {queue.ticket_count ?? 0}
                                </span>
                            </div>

                            {queue.description ? (
                                <p className="mt-2 text-sm leading-5 text-slate-500">{queue.description}</p>
                            ) : null}
                        </Link>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
