import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconPlus } from '@/Components/Icons';
import {
    Badge,
    Button,
    ButtonLink,
    Card,
    ConfirmDialog,
    EmptyState,
    PageHeader,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { QueueSummary } from '@/types/tickets';

type QueueRow = QueueSummary & {
    is_active: boolean;
    is_shared: boolean;
    position: number;
    tickets_count: number;
};

export default function AdminQueuesIndex({ queues }: { queues: QueueRow[] }) {
    const { t } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<QueueRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.queues.title')}
            actions={
                <ButtonLink href="/admin/queues/create" size="sm" icon={<IconPlus />}>
                    {t('admin.queues.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.queues.title')} description={t('admin.queues.subtitle')} />

            <Card>
                {queues.length === 0 ? (
                    <EmptyState title={t('tickets.queues.empty')} />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('admin.queues.columns.queue')}</TH>
                                <TH>{t('admin.queues.columns.team')}</TH>
                                <TH className="text-right">{t('admin.queues.columns.tickets')}</TH>
                                <TH className="text-right">{t('common.labels.actions')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {queues.map((queue) => (
                                <TR key={queue.id}>
                                    <TD>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={`/admin/queues/${queue.id}/edit`}
                                                className="font-medium text-slate-900 hover:text-brand-700"
                                            >
                                                {queue.name}
                                            </Link>
                                            {queue.is_active ? null : (
                                                <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                            )}
                                            {queue.is_shared ? null : <Badge tone="purple">{t('common.labels.system')}</Badge>}
                                        </div>
                                        {queue.description ? (
                                            <p className="mt-0.5 text-xs text-slate-500">{queue.description}</p>
                                        ) : null}
                                    </TD>
                                    <TD className="text-sm text-slate-600">{queue.team?.name ?? '—'}</TD>
                                    <TD className="text-right tabular-nums">{queue.tickets_count}</TD>
                                    <TD className="space-x-1 text-right">
                                        <ButtonLink href={`/admin/queues/${queue.id}/edit`} variant="ghost" size="sm">
                                            {t('common.actions.edit')}
                                        </ButtonLink>
                                        <Button variant="ghost" size="sm" onClick={() => setPendingDelete(queue)}>
                                            {t('common.actions.delete')}
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/queues/${pendingDelete.id}`, { onFinish: () => setPendingDelete(null) });
                    }
                }}
                message={t('admin.queues.confirm_delete')}
            />
        </AdminLayout>
    );
}
