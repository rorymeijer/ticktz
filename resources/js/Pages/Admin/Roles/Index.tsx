import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconPlus } from '@/Components/Icons';
import {
    Badge,
    ButtonLink,
    Card,
    ConfirmDialog,
    Dropdown,
    DropdownButton,
    DropdownLink,
    PageHeader,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

interface RoleRow {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    scope: 'admin' | 'agent' | 'requester';
    is_super: boolean;
    is_system: boolean;
    is_default: boolean;
    users_count: number;
    permissions_count: number;
}

export default function RolesIndex({ roles }: { roles: RoleRow[] }) {
    const { t } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<RoleRow | null>(null);

    const scopeTone = { admin: 'purple', agent: 'brand', requester: 'slate' } as const;

    return (
        <AdminLayout
            title={t('admin.roles.title')}
            actions={
                <ButtonLink href="/admin/roles/create" size="sm" icon={<IconPlus />}>
                    {t('admin.roles.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.roles.title')} description={t('admin.roles.subtitle')} />

            <Card>
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('admin.roles.columns.role')}</TH>
                            <TH>{t('admin.roles.columns.scope')}</TH>
                            <TH className="text-right">{t('admin.roles.columns.users')}</TH>
                            <TH className="text-right">{t('admin.roles.columns.permissions')}</TH>
                            <TH className="text-right">{t('common.labels.actions')}</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {roles.map((role) => (
                            <TR key={role.id}>
                                <TD>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Link
                                            href={`/admin/roles/${role.id}/edit`}
                                            className="font-medium text-slate-900 hover:text-brand-700"
                                        >
                                            {role.display_name}
                                        </Link>
                                        <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                                            {role.name}
                                        </code>
                                        {role.is_system ? <Badge>{t('common.labels.system')}</Badge> : null}
                                        {role.is_default ? <Badge tone="green">{t('common.labels.default')}</Badge> : null}
                                    </div>
                                    {role.description ? (
                                        <p className="mt-0.5 text-xs text-slate-500">{role.description}</p>
                                    ) : null}
                                </TD>
                                <TD>
                                    <Badge tone={scopeTone[role.scope]}>{t(`admin.roles.scope.${role.scope}`)}</Badge>
                                </TD>
                                <TD className="text-right tabular-nums">{role.users_count}</TD>
                                <TD className="text-right tabular-nums">
                                    {role.is_super ? '∞' : role.permissions_count}
                                </TD>
                                <TD className="text-right">
                                    <Dropdown
                                        trigger={
                                            <button
                                                type="button"
                                                className="rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100"
                                                aria-label={t('common.labels.actions')}
                                            >
                                                ⋯
                                            </button>
                                        }
                                    >
                                        <DropdownLink href={`/admin/roles/${role.id}/edit`}>
                                            {t('common.actions.edit')}
                                        </DropdownLink>
                                        <DropdownButton
                                            danger
                                            disabled={role.is_system}
                                            onClick={() => setPendingDelete(role)}
                                        >
                                            {t('common.actions.delete')}
                                        </DropdownButton>
                                    </Dropdown>
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
                        router.delete(`/admin/roles/${pendingDelete.id}`, { onFinish: () => setPendingDelete(null) });
                    }
                }}
                message={t('admin.roles.confirm_delete')}
            />
        </AdminLayout>
    );
}
