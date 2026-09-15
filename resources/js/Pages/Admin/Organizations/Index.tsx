import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { FilterBar } from '@/Components/Admin/FilterBar';
import { IconPlus } from '@/Components/Icons';
import {
    Badge,
    Button,
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
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { Paginated } from '@/types';

interface OrganizationRow {
    id: number;
    name: string;
    email_domains: string[] | null;
    is_active: boolean;
    shared_ticket_visibility: boolean;
    users_count: number;
}

export default function OrganizationsIndex({
    organizations,
    filters,
}: {
    organizations: Paginated<OrganizationRow>;
    filters: Record<string, string | undefined>;
}) {
    const { t } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<OrganizationRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.organizations.title')}
            actions={
                <ButtonLink href="/admin/organizations/create" size="sm" icon={<IconPlus />}>
                    {t('admin.organizations.create')}
                </ButtonLink>
            }
        >
            <PageHeader
                title={t('admin.organizations.title')}
                description={t('admin.organizations.subtitle')}
            />

            <Card>
                <FilterBar url="/admin/organizations" filters={filters} />

                {organizations.data.length === 0 ? (
                    <EmptyState title={t('admin.organizations.empty')} />
                ) : (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.organizations.columns.organization')}</TH>
                                    <TH>{t('admin.organizations.columns.domains')}</TH>
                                    <TH className="text-right">{t('admin.organizations.columns.users')}</TH>
                                    <TH className="text-right">{t('common.labels.actions')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {organizations.data.map((organization) => (
                                    <TR key={organization.id}>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={`/admin/organizations/${organization.id}/edit`}
                                                    className="font-medium text-slate-900 hover:text-brand-700"
                                                >
                                                    {organization.name}
                                                </Link>
                                                {organization.is_active ? null : (
                                                    <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                                )}
                                                {organization.shared_ticket_visibility ? (
                                                    <Badge tone="blue">
                                                        {t('admin.organizations.fields.shared_visibility')}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </TD>
                                        <TD>
                                            <div className="flex flex-wrap gap-1">
                                                {(organization.email_domains ?? []).map((domain) => (
                                                    <code
                                                        key={domain}
                                                        className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600"
                                                    >
                                                        @{domain}
                                                    </code>
                                                ))}
                                                {(organization.email_domains ?? []).length === 0 ? (
                                                    <span className="text-xs text-slate-400">
                                                        {t('common.labels.none')}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </TD>
                                        <TD className="text-right tabular-nums">{organization.users_count}</TD>
                                        <TD className="space-x-1 text-right">
                                            <ButtonLink
                                                href={`/admin/organizations/${organization.id}/edit`}
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {t('common.actions.edit')}
                                            </ButtonLink>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setPendingDelete(organization)}
                                            >
                                                {t('common.actions.delete')}
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={organizations} />
                    </>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/organizations/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.organizations.confirm_delete')}
            />
        </AdminLayout>
    );
}
