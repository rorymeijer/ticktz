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
    CheckboxGroup,
    Field,
    PageHeader,
    Select,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { RichTextField } from '@/Components/RichText/RichTextField';

interface RoleOption {
    id: number;
    name: string;
    display_name: string;
    description: string | null;
    scope: string;
}

interface UserPayload {
    id: number;
    name: string;
    email: string;
    username: string | null;
    locale: string | null;
    organization_id: number | null;
    manager_id: number | null;
    job_title: string | null;
    phone: string | null;
    signature: string | null;
    is_active: boolean;
    directory: 'local' | 'ldap';
    role_ids: number[];
    team_ids: number[];
}

export default function UserForm({
    user,
    roles,
    teams,
    organizations,
    managers,
    canAssignRoles,
}: {
    user: UserPayload | null;
    roles: RoleOption[];
    teams: { id: number; name: string }[];
    organizations: { id: number; name: string }[];
    managers: { id: number; name: string; email: string }[];
    canAssignRoles: boolean;
}) {
    const { t, locales } = useTranslations();
    const isEdit = user !== null;
    const directoryManaged = user?.directory === 'ldap';

    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        username: user?.username ?? '',
        password: '',
        password_confirmation: '',
        locale: user?.locale ?? '',
        organization_id: user?.organization_id ?? '',
        manager_id: user?.manager_id ?? '',
        job_title: user?.job_title ?? '',
        phone: user?.phone ?? '',
        signature: user?.signature ?? '',
        is_active: user?.is_active ?? true,
        role_ids: user?.role_ids ?? [],
        team_ids: user?.team_ids ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/users/${user.id}`);
        } else {
            form.post('/admin/users');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.users.edit') : t('admin.users.create')}>
            <PageHeader
                title={isEdit ? t('admin.users.edit') : t('admin.users.create')}
                description={t('admin.users.subtitle')}
                actions={
                    <ButtonLink href="/admin/users" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-3">
                <div className="space-y-5 lg:col-span-2">
                    <Card>
                        <CardHeader title={t('admin.users.fields.account')} />
                        <CardBody className="space-y-4">
                            {directoryManaged ? (
                                <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">
                                    {t('admin.users.directory_managed')}
                                </p>
                            ) : null}

                            <Field label={t('common.labels.name')} error={form.errors.name} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.name}
                                        disabled={directoryManaged}
                                        required
                                        onChange={(event) => form.setData('name', event.target.value)}
                                    />
                                )}
                            </Field>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('auth.register.email')} error={form.errors.email} required>
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            type="email"
                                            value={form.data.email}
                                            disabled={directoryManaged}
                                            required
                                            onChange={(event) => form.setData('email', event.target.value)}
                                        />
                                    )}
                                </Field>

                                <Field
                                    label={t('admin.users.fields.username')}
                                    help={t('admin.users.fields.username_help')}
                                    error={form.errors.username}
                                >
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            value={form.data.username}
                                            disabled={directoryManaged}
                                            onChange={(event) => form.setData('username', event.target.value)}
                                        />
                                    )}
                                </Field>
                            </div>

                            {directoryManaged ? null : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field
                                        label={t('admin.users.fields.password')}
                                        help={isEdit ? t('admin.users.fields.password_help') : undefined}
                                        error={form.errors.password}
                                        required={!isEdit}
                                    >
                                        {(props) => (
                                            <TextInput
                                                {...props}
                                                type="password"
                                                autoComplete="new-password"
                                                value={form.data.password}
                                                required={!isEdit}
                                                onChange={(event) => form.setData('password', event.target.value)}
                                            />
                                        )}
                                    </Field>

                                    <Field
                                        label={t('admin.users.fields.password_confirmation')}
                                        error={form.errors.password_confirmation}
                                        required={!isEdit}
                                    >
                                        {(props) => (
                                            <TextInput
                                                {...props}
                                                type="password"
                                                autoComplete="new-password"
                                                value={form.data.password_confirmation}
                                                required={!isEdit}
                                                onChange={(event) =>
                                                    form.setData('password_confirmation', event.target.value)
                                                }
                                            />
                                        )}
                                    </Field>
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('admin.users.fields.job_title')} error={form.errors.job_title}>
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            value={form.data.job_title}
                                            onChange={(event) => form.setData('job_title', event.target.value)}
                                        />
                                    )}
                                </Field>

                                <Field label={t('admin.users.fields.phone')} error={form.errors.phone}>
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            value={form.data.phone}
                                            onChange={(event) => form.setData('phone', event.target.value)}
                                        />
                                    )}
                                </Field>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('profile.information.locale')} error={form.errors.locale}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.locale}
                                            onChange={(event) => form.setData('locale', event.target.value)}
                                        >
                                            <option value="">{t('common.labels.default')}</option>
                                            {locales.map((option) => (
                                                <option key={option.code} value={option.code}>
                                                    {option.native}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field
                                    label={t('admin.users.fields.organization')}
                                    error={form.errors.organization_id}
                                >
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={String(form.data.organization_id ?? '')}
                                            onChange={(event) =>
                                                form.setData(
                                                    'organization_id',
                                                    event.target.value ? Number(event.target.value) : '',
                                                )
                                            }
                                        >
                                            <option value="">{t('common.labels.none')}</option>
                                            {organizations.map((organization) => (
                                                <option key={organization.id} value={organization.id}>
                                                    {organization.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field
                                    label={t('approvals.fields.manager')}
                                    error={form.errors.manager_id}
                                    help={t('approvals.fields.manager_help')}
                                >
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={String(form.data.manager_id ?? '')}
                                            onChange={(event) =>
                                                form.setData(
                                                    'manager_id',
                                                    event.target.value ? Number(event.target.value) : '',
                                                )
                                            }
                                        >
                                            <option value="">{t('approvals.fields.manager_none')}</option>
                                            {managers
                                                .filter((candidate) => candidate.id !== user?.id)
                                                .map((candidate) => (
                                                    <option key={candidate.id} value={candidate.id}>
                                                        {candidate.name}
                                                    </option>
                                                ))}
                                        </Select>
                                    )}
                                </Field>
                            </div>

                            {isEdit ? (
                                <RichTextField
                                    label={t('admin.users.fields.signature')}
                                    help={t('admin.users.fields.signature_help')}
                                    error={form.errors.signature}
                                    value={form.data.signature}
                                    onChange={(html) => form.setData('signature', html)}
                                    minHeight="6rem"
                                />
                            ) : null}

                            <Toggle
                                checked={form.data.is_active}
                                onChange={(value) => form.setData('is_active', value)}
                                label={t('admin.users.fields.active')}
                                description={t('admin.users.fields.active_help')}
                            />
                        </CardBody>
                    </Card>
                </div>

                <div className="space-y-5">
                    {canAssignRoles ? (
                        <Card>
                            <CardHeader
                                title={t('admin.users.fields.roles')}
                                description={t('admin.users.fields.roles_help')}
                            />
                            <CardBody>
                                <CheckboxGroup
                                    options={roles.map((role) => ({
                                        value: role.id,
                                        label: role.display_name,
                                        description: role.description,
                                    }))}
                                    selected={form.data.role_ids}
                                    onChange={(values) => form.setData('role_ids', values)}
                                />
                            </CardBody>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader title={t('admin.users.fields.teams')} />
                        <CardBody>
                            <CheckboxGroup
                                options={teams.map((team) => ({ value: team.id, label: team.name }))}
                                selected={form.data.team_ids}
                                onChange={(values) => form.setData('team_ids', values)}
                                emptyLabel={t('admin.teams.empty')}
                            />
                        </CardBody>
                    </Card>
                </div>

                <div className="lg:col-span-3">
                    <Card>
                        <CardFooter>
                            <ButtonLink href="/admin/users" variant="secondary">
                                {t('common.actions.cancel')}
                            </ButtonLink>
                            <Button type="submit" disabled={form.processing}>
                                {t('common.actions.save')}
                            </Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    );
}
