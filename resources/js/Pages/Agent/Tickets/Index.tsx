import { router } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { FilterBar, FilterSelect } from '@/Components/Admin/FilterBar';
import { IconPlus } from '@/Components/Icons';
import { TicketTable } from '@/Components/Tickets/TicketTable';
import { ButtonLink, Card, EmptyState, PageHeader, Pagination } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';
import type { QueueSummary, TicketListItem, TicketOptions } from '@/types/tickets';

export default function TicketsIndex({
    tickets,
    filters,
    sort,
    queue,
    queues,
    options,
    can,
}: {
    tickets: Paginated<TicketListItem>;
    filters: Record<string, string | undefined>;
    sort: { by: string; direction: 'asc' | 'desc' };
    queue: QueueSummary | null;
    queues: QueueSummary[];
    options: TicketOptions;
    can: { create: boolean; export: boolean };
}) {
    const { t } = useTranslations();

    const url = '/agent/tickets';
    const query = { ...filters, ...(queue ? { queue_slug: queue.slug } : {}) };

    const changeSort = (column: string) => {
        const direction = sort.by === column && sort.direction === 'desc' ? 'asc' : 'desc';

        router.get(
            url,
            { ...query, sort_by: column, sort_direction: direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const selectQueue = (slug: string | null) => {
        router.get(url, slug ? { queue_slug: slug } : {}, { preserveState: false });
    };

    return (
        <AppLayout
            title={queue?.name ?? t('tickets.title')}
            actions={
                can.create ? (
                    <ButtonLink href="/agent/tickets/create" size="sm" icon={<IconPlus />}>
                        {t('tickets.create')}
                    </ButtonLink>
                ) : null
            }
        >
            <PageHeader
                title={queue?.name ?? t('tickets.title')}
                description={queue?.description ?? t('tickets.subtitle')}
            />

            {/* Queue switcher. Queues are filters, so switching one simply
                replaces the query string. */}
            <div className="mb-4 flex flex-wrap gap-1.5">
                <QueueChip label={t('tickets.queues.all_tickets')} active={!queue} onClick={() => selectQueue(null)} />
                {queues.map((item) => (
                    <QueueChip
                        key={item.id}
                        label={item.name}
                        active={queue?.id === item.id}
                        onClick={() => selectQueue(item.slug)}
                    />
                ))}
            </div>

            <Card>
                <FilterBar url={url} filters={query} searchPlaceholder={t('tickets.placeholders.search')}>
                    <FilterSelect
                        url={url}
                        filters={query}
                        name="status"
                        label={t('tickets.filters.status')}
                        options={options.statuses.map((status) => ({
                            value: String(status.id),
                            label: status.name,
                        }))}
                    />
                    <FilterSelect
                        url={url}
                        filters={query}
                        name="priority"
                        label={t('tickets.filters.priority')}
                        options={options.priorities.map((priority) => ({
                            value: String(priority.id),
                            label: priority.name,
                        }))}
                    />
                    <FilterSelect
                        url={url}
                        filters={query}
                        name="assignee"
                        label={t('tickets.filters.assignee')}
                        options={[
                            { value: 'me', label: t('tickets.filters.assigned_to_me') },
                            { value: 'unassigned', label: t('tickets.filters.unassigned') },
                        ]}
                    />
                    <FilterSelect
                        url={url}
                        filters={query}
                        name="label"
                        label={t('tickets.filters.label')}
                        options={options.labels.map((label) => ({ value: String(label.id), label: label.name }))}
                    />
                    <FilterSelect
                        url={url}
                        filters={query}
                        name="sla"
                        label={t('sla.filters.label')}
                        options={[
                            { value: 'breached', label: t('sla.filters.breached') },
                            { value: 'at_risk', label: t('sla.filters.at_risk') },
                            { value: 'running', label: t('sla.filters.running') },
                            { value: 'paused', label: t('sla.filters.paused') },
                            { value: 'none', label: t('sla.filters.none') },
                        ]}
                    />
                </FilterBar>

                {tickets.data.length === 0 ? (
                    <EmptyState
                        title={t('tickets.empty.title')}
                        description={t('tickets.empty.description')}
                        action={
                            can.create ? (
                                <ButtonLink href="/agent/tickets/create">{t('tickets.empty.create')}</ButtonLink>
                            ) : null
                        }
                    />
                ) : (
                    <>
                        <TicketTable
                            tickets={tickets.data}
                            columns={queue?.columns ?? ['key', 'subject', 'status', 'priority', 'sla', 'requester', 'assignee', 'updated_at']}
                            sort={sort}
                            onSort={changeSort}
                        />
                        <Pagination page={tickets} />
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function QueueChip({ label, active, onClick }: { label: string; active: boolean; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                active
                    ? 'bg-brand-600 text-white'
                    : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:text-slate-900',
            )}
        >
            {label}
        </button>
    );
}
