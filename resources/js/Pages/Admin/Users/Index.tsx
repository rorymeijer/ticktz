import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { FilterBar, FilterSelect } from '@/Components/Admin/FilterBar';
import { IconPlus } from '@/Components/Icons';
import {
    Avatar,
    Badge,
    ButtonLink,
    Card,
    ConfirmDialog,
    Dropdown,
    DropdownButton,
    DropdownDivider,
    DropdownLink,
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

interface UserRow {
    id: number;
    name: string;
    email: string;
    username: string | null;
    initials: string;
    avatar_color: string;
    directory: 'local' | 'ldap';
    is_active: boolean;
    last_login_at: string | null;
    roles: { name: string; display_name: string }[];
    organization: { id: number; name: string } | null;
}

export default function UsersIndex({
    users,
    filters,
    roles,
}: {
    users: Paginated<UserRow>;
    filters: Record<string, string | undefined>;
    roles: { id: number; name: string; display_name: string }[];
}) {
    const { t, locale } = useTranslations();
    const [pendingDelete, setPendingDelete] = useState<UserRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.users.title')}
            actions={
                <ButtonLink href="/admin/users/create" size="sm" icon={<IconPlus />}>
                    {t('admin.users.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.users.title')} description={t('admin.users.subtitle')} />

            <Card>
                <FilterBar
                    url="/admin/users"
                    filters={filters}
                    searchPlaceholder={t('admin.users.search_placeholder')}
                >
                    <FilterSelect
                        url="/admin/users"
                        filters={filters}
                        name="role"
                        label={t('admin.users.filters.role')}
                        options={roles.map((role) => ({ value: role.name, label: role.display_name }))}
                    />
                    <FilterSelect
                        url="/admin/users"
                        filters={filters}
                        name="directory"
                        label={t('admin.users.filters.directory')}
                        options={[
                            { value: 'local', label: t('admin.users.source.local') },
                            { value: 'ldap', label: t('admin.users.source.ldap') },
                        ]}
                    />
                    <FilterSelect
                        url="/admin/users"
                        filters={filters}
                        name="status"
                        label={t('admin.users.filters.status')}
                        options={[
                            { value: 'active', label: t('admin.users.filters.active') },
                            { value: 'inactive', label: t('admin.users.filters.inactive') },
                        ]}
                    />
                </FilterBar>

                {users.data.length === 0 ? (
                    <EmptyState title={t('admin.users.empty')} description={t('common.empty.description')} />
                ) : (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.users.columns.user')}</TH>
                                    <TH>{t('admin.users.columns.roles')}</TH>
                                    <TH>{t('admin.users.columns.organization')}</TH>
                                    <TH>{t('admin.users.columns.source')}</TH>
                                    <TH>{t('admin.users.columns.last_login')}</TH>
                                    <TH className="text-right">{t('common.labels.actions')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {users.data.map((user) => (
                                    <TR key={user.id}>
                                        <TD>
                                            <div className="flex items-center gap-2.5">
                                                <Avatar
                                                    name={user.name}
                                                    initials={user.initials}
                                                    color={user.avatar_color}
                                                    size="sm"
                                                />
                                                <div className="min-w-0">
                                                    <Link
                                                        href={`/admin/users/${user.id}/edit`}
                                                        className="block truncate font-medium text-slate-900 hover:text-brand-700"
                                                    >
                                                        {user.name}
                                                    </Link>
                                                    <span className="block truncate text-xs text-slate-500">
                                                        {user.email}
                                                    </span>
                                                </div>
                                                {user.is_active ? null : (
                                                    <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                                )}
                                            </div>
                                        </TD>
                                        <TD>
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles.map((role) => (
                                                    <Badge key={role.name} tone="brand">
                                                        {role.display_name}
                                                    </Badge>
                                                ))}
                                                {user.roles.length === 0 ? (
                                                    <span className="text-xs text-slate-400">
                                                        {t('common.labels.none')}
                                                    </span>
                                                ) : null}
                                            </div>
                                        </TD>
                                        <TD className="text-sm text-slate-600">{user.organization?.name ?? '—'}</TD>
                                        <TD>
                                            <Badge tone={user.directory === 'ldap' ? 'blue' : 'slate'}>
                                                {t(`admin.users.source.${user.directory}`)}
                                            </Badge>
                                        </TD>
                                        <TD className="whitespace-nowrap text-xs text-slate-500">
                                            {user.last_login_at
                                                ? formatDateTime(user.last_login_at, locale)
                                                : t('admin.users.never_signed_in')}
                                        </TD>
                                        <TD className="text-right">
                                            <Dropdown
                                                trigger={
                                                    <button
                                                        type="button"
                                                        className="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100"
                                                        aria-label={t('common.labels.actions')}
                                                    >
                                                        ⋯
                                                    </button>
                                                }
                                            >
                                                <DropdownLink href={`/admin/users/${user.id}/edit`}>
                                                    {t('common.actions.edit')}
                                                </DropdownLink>
                                                <DropdownButton
                                                    onClick={() =>
                                                        router.patch(`/admin/users/${user.id}/active`, {}, { preserveScroll: true })
                                                    }
                                                >
                                                    {user.is_active
                                                        ? t('common.labels.disabled')
                                                        : t('common.labels.enabled')}
                                                </DropdownButton>
                                                <DropdownDivider />
                                                <DropdownButton danger onClick={() => setPendingDelete(user)}>
                                                    {t('common.actions.delete')}
                                                </DropdownButton>
                                            </Dropdown>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={users} />
                    </>
                )}
            </Card>

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/users/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.users.confirm_delete', { name: pendingDelete?.name ?? '' })}
            />
        </AdminLayout>
    );
}
