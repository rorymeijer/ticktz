import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { FilterBar } from '@/Components/Admin/FilterBar';
import { IconPlus } from '@/Components/Icons';
import {
    Badge,
    ButtonLink,
    Card,
    ConfirmDialog,
    EmptyState,
    PageHeader,
    Pagination,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    Button,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { Paginated } from '@/types';

interface TeamRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    email: string | null;
    is_active: boolean;
    members_count: number;
}

export default function TeamsIndex({
    teams,
    filters,
}: {
    teams: Paginated<TeamRow>;
    filters: Record<string, string | undefined>;
}) {
    const { t } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<TeamRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.teams.title')}
            actions={
                <ButtonLink href="/admin/teams/create" size="sm" icon={<IconPlus />}>
                    {t('admin.teams.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.teams.title')} description={t('admin.teams.subtitle')} />

            <Card>
                <FilterBar url="/admin/teams" filters={filters} />

                {teams.data.length === 0 ? (
                    <EmptyState title={t('admin.teams.empty')} />
                ) : (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.teams.columns.team')}</TH>
                                    <TH>{t('admin.teams.columns.email')}</TH>
                                    <TH className="text-right">{t('admin.teams.columns.members')}</TH>
                                    <TH className="text-right">{t('common.labels.actions')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {teams.data.map((team) => (
                                    <TR key={team.id}>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/admin/teams/${team.id}/edit`}
                                                    className="font-medium text-slate-900 hover:text-brand-700"
                                                >
                                                    {team.name}
                                                </Link>
                                                {team.is_active ? null : (
                                                    <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                                )}
                                            </div>
                                            {team.description ? (
                                                <p className="mt-0.5 text-xs text-slate-500">{team.description}</p>
                                            ) : null}
                                        </TD>
                                        <TD className="text-sm text-slate-600">{team.email ?? '—'}</TD>
                                        <TD className="text-right tabular-nums">{team.members_count}</TD>
                                        <TD className="space-x-1 text-right">
                                            <ButtonLink
                                                href={`/admin/teams/${team.id}/edit`}
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {t('common.actions.edit')}
                                            </ButtonLink>
                                            <Button variant="ghost" size="sm" onClick={() => setPendingDelete(team)}>
                                                {t('common.actions.delete')}
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={teams} />
                    </>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/teams/${pendingDelete.id}`, { onFinish: () => setPendingDelete(null) });
                    }
                }}
                message={t('admin.teams.confirm_delete')}
            />
        </AdminLayout>
    );
}
