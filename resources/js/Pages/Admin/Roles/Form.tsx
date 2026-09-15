import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    Field,
    PageHeader,
    Select,
    TextInput,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';

interface PermissionGroup {
    group: string;
    permissions: { name: string }[];
}

interface RolePayload {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    scope: 'admin' | 'agent' | 'requester';
    is_super: boolean;
    is_system: boolean;
    is_default: boolean;
    permissions: string[];
}

export default function RoleForm({
    role,
    permissionGroups,
}: {
    role: RolePayload | null;
    permissionGroups: PermissionGroup[];
}) {
    const { t } = useTranslations();
    const isEdit = role !== null;

    const form = useForm({
        name: role?.name ?? '',
        display_name: role?.display_name ?? '',
        description: role?.description ?? '',
        scope: role?.scope ?? 'agent',
        is_default: role?.is_default ?? false,
        permissions: role?.permissions ?? ([] as string[]),
    });

    const toggle = (permission: string) => {
        form.setData(
            'permissions',
            form.data.permissions.includes(permission)
                ? form.data.permissions.filter((item) => item !== permission)
                : [...form.data.permissions, permission],
        );
    };

    const toggleGroup = (group: PermissionGroup, on: boolean) => {
        const names = group.permissions.map((permission) => permission.name);

        form.setData(
            'permissions',
            on
                ? Array.from(new Set([...form.data.permissions, ...names]))
                : form.data.permissions.filter((item) => !names.includes(item)),
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/roles/${role.id}`);
        } else {
            form.post('/admin/roles');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.roles.edit') : t('admin.roles.create')}>
            <PageHeader
                title={isEdit ? t('admin.roles.edit') : t('admin.roles.create')}
                description={t('admin.roles.subtitle')}
                actions={
                    <ButtonLink href="/admin/roles" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="space-y-5">
                <Card>
                    <CardBody className="space-y-4">
                        {role?.is_system ? (
                            <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">
                                {t('admin.roles.system_role')}
                            </p>
                        ) : null}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('admin.roles.fields.display_name')} error={form.errors.display_name} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.display_name}
                                        required
                                        onChange={(event) => form.setData('display_name', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('admin.roles.fields.name')}
                                help={t('admin.roles.fields.name_help')}
                                error={form.errors.name}
                                required
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.name}
                                        disabled={role?.is_system}
                                        required
                                        onChange={(event) => form.setData('name', event.target.value)}
                                    />
                                )}
                            </Field>
                        </div>

                        <Field label={t('common.labels.description')} error={form.errors.description}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.roles.fields.scope')}
                            help={t('admin.roles.fields.scope_help')}
                            error={form.errors.scope}
                            required
                        >
                            {(props) => (
                                <Select
                                    {...props}
                                    value={form.data.scope}
                                    disabled={role?.is_system}
                                    onChange={(event) =>
                                        form.setData('scope', event.target.value as RolePayload['scope'])
                                    }
                                >
                                    <option value="admin">{t('admin.roles.scope.admin')}</option>
                                    <option value="agent">{t('admin.roles.scope.agent')}</option>
                                    <option value="requester">{t('admin.roles.scope.requester')}</option>
                                </Select>
                            )}
                        </Field>

                        <Toggle
                            checked={form.data.is_default}
                            onChange={(value) => form.setData('is_default', value)}
                            label={t('admin.roles.fields.is_default')}
                        />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('admin.roles.fields.permissions')} />
                    <CardBody>
                        {role?.is_super ? (
                            <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {t('admin.roles.super_role')}
                            </p>
                        ) : (
                            <div className="grid gap-5 md:grid-cols-2">
                                {permissionGroups.map((group) => {
                                    const names = group.permissions.map((permission) => permission.name);
                                    const allSelected = names.every((name) => form.data.permissions.includes(name));

                                    return (
                                        <fieldset key={group.group} className="rounded-lg border border-slate-200 p-3">
                                            <legend className="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                {t(`admin.permissions.groups.${group.group}`)}
                                                <button
                                                    type="button"
                                                    onClick={() => toggleGroup(group, !allSelected)}
                                                    className="font-medium normal-case tracking-normal text-brand-600 hover:text-brand-700"
                                                >
                                                    {allSelected ? t('admin.roles.clear_all') : t('admin.roles.select_all')}
                                                </button>
                                            </legend>

                                            <div className="space-y-1">
                                                {group.permissions.map((permission) => (
                                                    <label
                                                        key={permission.name}
                                                        className="flex cursor-pointer items-start gap-2 rounded-md px-1 py-0.5 hover:bg-slate-50"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={form.data.permissions.includes(permission.name)}
                                                            onChange={() => toggle(permission.name)}
                                                            className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                        />
                                                        <span className="min-w-0">
                                                            <span className="block text-sm text-slate-700">
                                                                {t(`admin.permissions.${permission.name}`)}
                                                            </span>
                                                            <code
                                                                className={cn(
                                                                    'block font-mono text-[10px] text-slate-400',
                                                                )}
                                                            >
                                                                {permission.name}
                                                            </code>
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    );
                                })}
                            </div>
                        )}
                    </CardBody>
                    <CardFooter>
                        <ButtonLink href="/admin/roles" variant="secondary">
                            {t('common.actions.cancel')}
                        </ButtonLink>
                        <Button type="submit" disabled={form.processing}>
                            {t('common.actions.save')}
                        </Button>
                    </CardFooter>
                </Card>
            </form>
        </AdminLayout>
    );
}
