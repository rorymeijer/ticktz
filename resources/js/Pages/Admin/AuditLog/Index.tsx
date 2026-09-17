import AdminLayout from '@/Layouts/AdminLayout';
import { FilterBar, FilterSelect } from '@/Components/Admin/FilterBar';
import {
    Badge,
    Card,
    EmptyState,
    PageHeader,
    Pagination,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import type { Paginated } from '@/types';

interface AuditEntry {
    id: number;
    event: string;
    description: string | null;
    actor_type: 'user' | 'system' | 'automation' | 'email' | 'api';
    actor: { name: string; email?: string };
    subject_type: string;
    subject_id: number;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string | null;
}

const actorTones = {
    user: 'slate',
    system: 'blue',
    automation: 'purple',
    email: 'amber',
    api: 'green',
} as const;

export default function AuditLogIndex({
    entries,
    filters,
    events,
}: {
    entries: Paginated<AuditEntry>;
    filters: Record<string, string | undefined>;
    events: string[];
}) {
    const { t, locale } = useTranslations();

    return (
        <AdminLayout title={t('admin.audit.title')}>
            <PageHeader title={t('admin.audit.title')} description={t('admin.audit.subtitle')} />

            <Card>
                <FilterBar url="/admin/audit-log" filters={filters}>
                    <FilterSelect
                        url="/admin/audit-log"
                        filters={filters}
                        name="event"
                        label={t('admin.audit.filters.event')}
                        options={events.map((event) => ({ value: event, label: event }))}
                    />
                    <FilterSelect
                        url="/admin/audit-log"
                        filters={filters}
                        name="actor_type"
                        label={t('admin.audit.filters.actor_type')}
                        options={(['user', 'system', 'automation', 'email', 'api'] as const).map((type) => ({
                            value: type,
                            label: t(`admin.audit.actor_types.${type}`),
                        }))}
                    />
                </FilterBar>

                {entries.data.length === 0 ? (
                    <EmptyState title={t('admin.audit.empty')} />
                ) : (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.audit.columns.when')}</TH>
                                    <TH>{t('admin.audit.columns.actor')}</TH>
                                    <TH>{t('admin.audit.columns.event')}</TH>
                                    <TH>{t('admin.audit.columns.subject')}</TH>
                                    <TH>{t('admin.audit.columns.details')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {entries.data.map((entry) => (
                                    <TR key={entry.id}>
                                        <TD className="whitespace-nowrap text-xs text-slate-500">
                                            {formatDateTime(entry.created_at, locale)}
                                        </TD>
                                        <TD>
                                            <div className="flex items-center gap-1.5">
                                                <Badge tone={actorTones[entry.actor_type]}>
                                                    {t(`admin.audit.actor_types.${entry.actor_type}`)}
                                                </Badge>
                                                <span className="text-sm text-slate-700">{entry.actor.name}</span>
                                            </div>
                                        </TD>
                                        <TD>
                                            <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                                                {entry.event}
                                            </code>
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm text-slate-600">
                                            {entry.subject_type}
                                            {entry.subject_id ? ` #${entry.subject_id}` : ''}
                                        </TD>
                                        <TD>
                                            <p className="text-sm text-slate-700">{entry.description ?? '—'}</p>
                                            <ChangeSummary entry={entry} />
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={entries} />
                    </>
                )}
            </Card>
        </AdminLayout>
    );
}

function ChangeSummary({ entry }: { entry: AuditEntry }) {
    const { t } = useTranslations();

    if (!entry.new_values || Object.keys(entry.new_values).length === 0) {
        return null;
    }

    const render = (value: unknown): string => {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'boolean') return value ? '✓' : '✗';
        if (typeof value === 'object') return JSON.stringify(value);

        return String(value);
    };

    return (
        <details className="mt-1">
            <summary className="cursor-pointer text-xs text-brand-600 hover:text-brand-700">
                {t('admin.audit.changes')}
            </summary>
            <dl className="mt-1 space-y-0.5">
                {Object.entries(entry.new_values).map(([key, value]) => (
                    <div key={key} className="flex flex-wrap items-baseline gap-1 text-[11px]">
                        <dt className="font-mono text-slate-500">{key}</dt>
                        {entry.old_values && key in entry.old_values ? (
                            <dd className="text-slate-500 line-through">{render(entry.old_values[key])}</dd>
                        ) : null}
                        <dd className="text-slate-700">{render(value)}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}
