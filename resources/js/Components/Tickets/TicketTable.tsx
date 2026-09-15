import { Link } from '@inertiajs/react';

import { LabelChip, PriorityBadge, StatusBadge } from '@/Components/Tickets/Badges';
import { SlaBadge } from '@/Components/Tickets/SlaBadge';
import { Avatar, TBody, TD, TH, THead, TR, Table } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime, relativeTime } from '@/lib/datetime';
import type { TicketListItem } from '@/types/tickets';

/**
 * The ticket list. Which columns appear is a property of the queue, so the
 * same component renders a five-column triage view and a ten-column
 * management view without a second implementation.
 */
export function TicketTable({
    tickets,
    columns,
    sort,
    onSort,
}: {
    tickets: TicketListItem[];
    columns: string[];
    sort?: { by: string; direction: 'asc' | 'desc' };
    onSort?: (column: string) => void;
}) {
    const { t, locale } = useTranslations();

    const sortable: Record<string, string> = {
        key: 'key',
        subject: 'subject',
        priority: 'priority_id',
        created_at: 'created_at',
        updated_at: 'updated_at',
        sla: 'sla_due_at',
    };

    const header = (column: string) => {
        const sortColumn = sortable[column];
        const label = t(`tickets.columns.${column}`);

        if (!sortColumn || !onSort) {
            return label;
        }

        const active = sort?.by === sortColumn;

        return (
            <button
                type="button"
                onClick={() => onSort(sortColumn)}
                className="inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-700"
            >
                {label}
                <span aria-hidden="true" className={active ? 'text-brand-600' : 'text-slate-300'}>
                    {active && sort?.direction === 'asc' ? '▲' : '▼'}
                </span>
            </button>
        );
    };

    const cell = (ticket: TicketListItem, column: string) => {
        switch (column) {
            case 'key':
                return (
                    <Link
                        href={`/agent/tickets/${ticket.key}`}
                        className="font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                    >
                        {ticket.key}
                    </Link>
                );
            case 'subject':
                return (
                    <div className="min-w-0">
                        <Link
                            href={`/agent/tickets/${ticket.key}`}
                            className="block truncate font-medium text-slate-900 hover:text-brand-700"
                        >
                            {ticket.subject}
                        </Link>
                        {ticket.labels.length > 0 ? (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {ticket.labels.map((label) => (
                                    <LabelChip key={label.id} label={label} />
                                ))}
                            </div>
                        ) : null}
                    </div>
                );
            case 'status':
                return <StatusBadge status={ticket.status} />;
            case 'priority':
                return <PriorityBadge priority={ticket.priority} />;
            case 'requester':
                return ticket.requester ? (
                    <div className="flex items-center gap-2">
                        <Avatar
                            name={ticket.requester.name}
                            initials={ticket.requester.initials}
                            color={ticket.requester.avatar_color}
                            size="xs"
                        />
                        <span className="truncate text-sm">{ticket.requester.name}</span>
                    </div>
                ) : (
                    '—'
                );
            case 'assignee':
                return ticket.assignee ? (
                    <div className="flex items-center gap-2">
                        <Avatar
                            name={ticket.assignee.name}
                            initials={ticket.assignee.initials}
                            color={ticket.assignee.avatar_color}
                            size="xs"
                        />
                        <span className="truncate text-sm">{ticket.assignee.name}</span>
                    </div>
                ) : (
                    <span className="text-xs text-slate-400">{t('common.labels.unassigned')}</span>
                );
            case 'team':
                return <span className="text-sm">{ticket.team?.name ?? '—'}</span>;
            case 'organization':
                return <span className="text-sm">{ticket.organization?.name ?? '—'}</span>;
            case 'labels':
                return (
                    <div className="flex flex-wrap gap-1">
                        {ticket.labels.map((label) => (
                            <LabelChip key={label.id} label={label} />
                        ))}
                    </div>
                );
            case 'sla':
                return ticket.sla ? (
                    <SlaBadge timer={ticket.sla} compact />
                ) : (
                    <span className="text-xs text-slate-400">—</span>
                );
            case 'created_at':
                return (
                    <span className="whitespace-nowrap text-xs text-slate-500" title={formatDateTime(ticket.created_at, locale)}>
                        {relativeTime(ticket.created_at, t)}
                    </span>
                );
            case 'updated_at':
                return (
                    <span className="whitespace-nowrap text-xs text-slate-500" title={formatDateTime(ticket.updated_at, locale)}>
                        {relativeTime(ticket.updated_at, t)}
                    </span>
                );
            default:
                return null;
        }
    };

    return (
        <Table>
            <THead>
                <TR>
                    {columns.map((column) => (
                        <TH key={column}>{header(column)}</TH>
                    ))}
                </TR>
            </THead>
            <TBody>
                {tickets.map((ticket) => (
                    <TR key={ticket.id}>
                        {columns.map((column) => (
                            <TD key={column} className={column === 'subject' ? 'max-w-md' : undefined}>
                                {cell(ticket, column)}
                            </TD>
                        ))}
                    </TR>
                ))}
            </TBody>
        </Table>
    );
}
