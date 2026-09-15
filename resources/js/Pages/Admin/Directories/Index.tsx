import { router, useForm } from '@inertiajs/react';
import { useState, type FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconAlert, IconPlus } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    ConfirmDialog,
    EmptyState,
    Field,
    PageHeader,
    Select,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';

interface Directory {
    id: number;
    name: string;
    connection: string;
    is_enabled: boolean;
    hosts: string[];
    port: number;
    encryption: 'none' | 'ssl' | 'tls';
    timeout: number;
    base_dn: string;
    bind_dn: string | null;
    has_bind_password: boolean;
    login_attribute: string;
    user_filter: string | null;
    attribute_map: Record<string, string>;
    sync_groups: boolean;
    group_base_dn: string | null;
    group_filter: string | null;
    group_member_attribute: string;
    group_role_map: Record<string, string>;
    default_role_id: number | null;
    auto_provision: boolean;
    deactivate_missing: boolean;
    last_tested_at: string | null;
    last_test_result: string | null;
}

interface RoleOption {
    id: number;
    name: string;
    display_name: string;
}

export default function DirectoriesIndex({
    directories,
    extensionAvailable,
    roles,
    defaultAttributeMap,
}: {
    directories: Directory[];
    extensionAvailable: boolean;
    roles: RoleOption[];
    defaultAttributeMap: Record<string, string>;
}) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<Directory | 'new' | null>(null);
    const [pendingDelete, setPendingDelete] = useState<Directory | null>(null);

    return (
        <AdminLayout
            title={t('admin.directories.title')}
            actions={
                <Button size="sm" icon={<IconPlus />} onClick={() => setEditing('new')}>
                    {t('admin.directories.create')}
                </Button>
            }
        >
            <PageHeader title={t('admin.directories.title')} description={t('admin.directories.subtitle')} />

            {extensionAvailable ? null : (
                <div className="mb-5 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <IconAlert className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{t('admin.directories.extension_missing')}</p>
                </div>
            )}

            {directories.length === 0 && editing === null ? (
                <Card>
                    <EmptyState
                        title={t('admin.directories.none')}
                        action={<Button onClick={() => setEditing('new')}>{t('admin.directories.create')}</Button>}
                    />
                </Card>
            ) : null}

            <div className="space-y-4">
                {directories.map((directory) => (
                    <Card key={directory.id}>
                        <CardHeader
                            title={
                                <span className="flex flex-wrap items-center gap-2">
                                    {directory.name}
                                    <Badge tone={directory.is_enabled ? 'green' : 'slate'}>
                                        {directory.is_enabled ? t('common.labels.enabled') : t('common.labels.disabled')}
                                    </Badge>
                                    <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500">
                                        {directory.connection}
                                    </code>
                                </span>
                            }
                            description={`${directory.hosts.join(', ')}:${directory.port} · ${directory.base_dn}`}
                            actions={
                                <>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() =>
                                            router.post(`/admin/directories/${directory.id}/test`, {}, { preserveScroll: true })
                                        }
                                    >
                                        {t('admin.directories.test')}
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => setEditing(directory)}>
                                        {t('common.actions.edit')}
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => setPendingDelete(directory)}>
                                        {t('common.actions.delete')}
                                    </Button>
                                </>
                            }
                        />
                        {directory.last_tested_at ? (
                            <CardBody className="py-2.5 text-xs text-slate-500">
                                {t('admin.directories.last_tested', {
                                    time: formatDateTime(directory.last_tested_at, 'en'),
                                })}
                                {directory.last_test_result ? ` — ${directory.last_test_result}` : null}
                            </CardBody>
                        ) : null}
                    </Card>
                ))}
            </div>

            {editing !== null ? (
                <DirectoryForm
                    directory={editing === 'new' ? null : editing}
                    roles={roles}
                    defaultAttributeMap={defaultAttributeMap}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/directories/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.directories.confirm_delete')}
            />
        </AdminLayout>
    );
}

function DirectoryForm({
    directory,
    roles,
    defaultAttributeMap,
    onClose,
}: {
    directory: Directory | null;
    roles: RoleOption[];
    defaultAttributeMap: Record<string, string>;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const isEdit = directory !== null;

    const form = useForm({
        name: directory?.name ?? '',
        connection: directory?.connection ?? 'default',
        is_enabled: directory?.is_enabled ?? false,
        hosts: directory?.hosts ?? ([] as string[]),
        port: directory?.port ?? 389,
        encryption: directory?.encryption ?? 'none',
        timeout: directory?.timeout ?? 5,
        base_dn: directory?.base_dn ?? '',
        bind_dn: directory?.bind_dn ?? '',
        bind_password: '',
        login_attribute: directory?.login_attribute ?? 'samaccountname',
        user_filter: directory?.user_filter ?? '',
        attribute_map: directory?.attribute_map ?? defaultAttributeMap,
        sync_groups: directory?.sync_groups ?? true,
        group_base_dn: directory?.group_base_dn ?? '',
        group_filter: directory?.group_filter ?? '',
        group_member_attribute: directory?.group_member_attribute ?? 'memberof',
        group_role_map: directory?.group_role_map ?? ({} as Record<string, string>),
        default_role_id: directory?.default_role_id ?? '',
        auto_provision: directory?.auto_provision ?? true,
        deactivate_missing: directory?.deactivate_missing ?? false,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = { onSuccess: () => onClose() };

        if (isEdit) {
            form.put(`/admin/directories/${directory.id}`, options);
        } else {
            form.post('/admin/directories', options);
        }
    };

    const groupRows = Object.entries(form.data.group_role_map);

    const setGroupRole = (index: number, group: string, role: string) => {
        const entries = Object.entries(form.data.group_role_map);
        entries[index] = [group, role];
        form.setData('group_role_map', Object.fromEntries(entries.filter(([key]) => key.trim() !== '')));
    };

    return (
        <Card className="mt-5">
            <CardHeader title={isEdit ? t('admin.directories.edit') : t('admin.directories.create')} />
            <form onSubmit={submit}>
                <CardBody className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('admin.directories.fields.name')} error={form.errors.name} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.name}
                                    required
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.directories.fields.connection')}
                            help={t('admin.directories.fields.connection_help')}
                            error={form.errors.connection}
                            required
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.connection}
                                    required
                                    className="font-mono"
                                    onChange={(event) => form.setData('connection', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label={t('admin.directories.fields.hosts')}
                        help={t('admin.directories.fields.hosts_help')}
                        error={form.errors.hosts}
                        required
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={2}
                                value={form.data.hosts.join('\n')}
                                placeholder="dc01.example.org"
                                onChange={(event) =>
                                    form.setData(
                                        'hosts',
                                        event.target.value.split('\n').map((line) => line.trim()).filter(Boolean),
                                    )
                                }
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('admin.directories.fields.port')} error={form.errors.port} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="number"
                                    value={form.data.port}
                                    required
                                    onChange={(event) => form.setData('port', Number(event.target.value))}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.directories.fields.encryption')} error={form.errors.encryption} required>
                            {(props) => (
                                <Select
                                    {...props}
                                    value={form.data.encryption}
                                    onChange={(event) =>
                                        form.setData('encryption', event.target.value as Directory['encryption'])
                                    }
                                >
                                    <option value="none">{t('admin.directories.encryption.none')}</option>
                                    <option value="ssl">{t('admin.directories.encryption.ssl')}</option>
                                    <option value="tls">{t('admin.directories.encryption.tls')}</option>
                                </Select>
                            )}
                        </Field>

                        <Field label={t('admin.directories.fields.timeout')} error={form.errors.timeout} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="number"
                                    value={form.data.timeout}
                                    required
                                    onChange={(event) => form.setData('timeout', Number(event.target.value))}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label={t('admin.directories.fields.base_dn')} error={form.errors.base_dn} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.base_dn}
                                required
                                className="font-mono text-xs"
                                placeholder="dc=example,dc=org"
                                onChange={(event) => form.setData('base_dn', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('admin.directories.fields.bind_dn')}
                            help={t('admin.directories.fields.bind_dn_help')}
                            error={form.errors.bind_dn}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.bind_dn}
                                    className="font-mono text-xs"
                                    onChange={(event) => form.setData('bind_dn', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.directories.fields.bind_password')}
                            help={t('admin.directories.fields.bind_password_help')}
                            error={form.errors.bind_password}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.bind_password}
                                    placeholder={directory?.has_bind_password ? '••••••••' : ''}
                                    onChange={(event) => form.setData('bind_password', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('admin.directories.fields.login_attribute')}
                            help={t('admin.directories.fields.login_attribute_help')}
                            error={form.errors.login_attribute}
                            required
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.login_attribute}
                                    required
                                    className="font-mono"
                                    onChange={(event) => form.setData('login_attribute', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.directories.fields.user_filter')} error={form.errors.user_filter}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.user_filter}
                                    className="font-mono text-xs"
                                    placeholder="(objectClass=user)"
                                    onChange={(event) => form.setData('user_filter', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <fieldset>
                        <legend className="text-sm font-medium text-slate-700">
                            {t('admin.directories.fields.attribute_map')}
                        </legend>
                        <p className="mb-2 text-xs text-slate-500">
                            {t('admin.directories.fields.attribute_map_help')}
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {Object.keys(defaultAttributeMap).map((column) => (
                                <label key={column} className="flex items-center gap-2">
                                    <span className="w-24 shrink-0 text-xs font-medium text-slate-600">{column}</span>
                                    <TextInput
                                        value={form.data.attribute_map[column] ?? ''}
                                        className="font-mono text-xs"
                                        onChange={(event) =>
                                            form.setData('attribute_map', {
                                                ...form.data.attribute_map,
                                                [column]: event.target.value,
                                            })
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <Toggle
                        checked={form.data.sync_groups}
                        onChange={(value) => form.setData('sync_groups', value)}
                        label={t('admin.directories.fields.sync_groups')}
                    />

                    {form.data.sync_groups ? (
                        <div className="space-y-4 rounded-lg border border-slate-200 p-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label={t('admin.directories.fields.group_member_attribute')}
                                    error={form.errors.group_member_attribute}
                                    required
                                >
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            value={form.data.group_member_attribute}
                                            required
                                            className="font-mono"
                                            onChange={(event) =>
                                                form.setData('group_member_attribute', event.target.value)
                                            }
                                        />
                                    )}
                                </Field>

                                <Field
                                    label={t('admin.directories.fields.default_role')}
                                    help={t('admin.directories.fields.default_role_help')}
                                    error={form.errors.default_role_id}
                                >
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={String(form.data.default_role_id ?? '')}
                                            onChange={(event) =>
                                                form.setData(
                                                    'default_role_id',
                                                    event.target.value ? Number(event.target.value) : '',
                                                )
                                            }
                                        >
                                            <option value="">{t('common.labels.none')}</option>
                                            {roles.map((role) => (
                                                <option key={role.id} value={role.id}>
                                                    {role.display_name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            </div>

                            <div>
                                <p className="text-sm font-medium text-slate-700">
                                    {t('admin.directories.fields.group_role_map')}
                                </p>
                                <p className="mb-2 text-xs text-slate-500">
                                    {t('admin.directories.fields.group_role_map_help')}
                                </p>

                                <div className="space-y-2">
                                    {[...groupRows, ['', '']].map(([group, role], index) => (
                                        <div key={`${group}-${index}`} className="flex items-center gap-2">
                                            <TextInput
                                                value={group}
                                                placeholder="Servicedesk"
                                                className="font-mono text-xs"
                                                onChange={(event) => setGroupRole(index, event.target.value, role)}
                                            />
                                            <span aria-hidden="true" className="text-slate-400">
                                                →
                                            </span>
                                            <Select
                                                value={role}
                                                aria-label={t('admin.directories.fields.group_role_map')}
                                                onChange={(event) => setGroupRole(index, group, event.target.value)}
                                            >
                                                <option value="">{t('common.labels.none')}</option>
                                                {roles.map((option) => (
                                                    <option key={option.id} value={option.name}>
                                                        {option.display_name}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <Toggle
                        checked={form.data.auto_provision}
                        onChange={(value) => form.setData('auto_provision', value)}
                        label={t('admin.directories.fields.auto_provision')}
                    />

                    <Toggle
                        checked={form.data.deactivate_missing}
                        onChange={(value) => form.setData('deactivate_missing', value)}
                        label={t('admin.directories.fields.deactivate_missing')}
                    />

                    <Toggle
                        checked={form.data.is_enabled}
                        onChange={(value) => form.setData('is_enabled', value)}
                        label={t('admin.directories.fields.enabled')}
                    />
                </CardBody>
                <CardFooter>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {t('common.actions.save')}
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}
