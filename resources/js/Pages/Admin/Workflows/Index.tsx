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
    PageHeader,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

interface WorkflowRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    statuses_count: number;
    transitions_count: number;
    ticket_count: number;
}

export default function WorkflowsIndex({ workflows }: { workflows: WorkflowRow[] }) {
    const { t } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<WorkflowRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.workflows.title')}
            actions={
                <ButtonLink href="/admin/workflows/create" size="sm" icon={<IconPlus />}>
                    {t('admin.workflows.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.workflows.title')} description={t('admin.workflows.subtitle')} />

            <Card>
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('admin.workflows.columns.workflow')}</TH>
                            <TH className="text-right">{t('admin.workflows.columns.statuses')}</TH>
                            <TH className="text-right">{t('admin.workflows.columns.transitions')}</TH>
                            <TH className="text-right">{t('admin.workflows.columns.tickets')}</TH>
                            <TH className="text-right">{t('common.labels.actions')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {workflows.map((workflow) => (
                            <TR key={workflow.id}>
                                <TD>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={`/admin/workflows/${workflow.id}/edit`}
                                            className="font-medium text-slate-900 hover:text-brand-700"
                                        >
                                            {workflow.name}
                                        </Link>
                                        {workflow.is_default ? (
                                            <Badge tone="green">{t('common.labels.default')}</Badge>
                                        ) : null}
                                        {workflow.is_active ? null : (
                                            <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                        )}
                                    </div>
                                    {workflow.description ? (
                                        <p className="mt-0.5 text-xs text-slate-500">{workflow.description}</p>
                                    ) : null}
                                </TD>
                                <TD className="text-right tabular-nums">{workflow.statuses_count}</TD>
                                <TD className="text-right tabular-nums">{workflow.transitions_count}</TD>
                                <TD className="text-right tabular-nums">{workflow.ticket_count}</TD>
                                <TD className="space-x-1 text-right">
                                    <ButtonLink href={`/admin/workflows/${workflow.id}/edit`} variant="ghost" size="sm">
                                        {t('common.actions.edit')}
                                    </ButtonLink>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={workflow.ticket_count > 0}
                                        onClick={() => setPendingDelete(workflow)}
                                    >
                                        {t('common.actions.delete')}
                                    </Button>
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/workflows/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.workflows.confirm_delete')}
            />
        </AdminLayout>
    );
}
