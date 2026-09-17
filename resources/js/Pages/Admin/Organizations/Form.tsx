import { useForm } from '@inertiajs/react';
import { useEffect, type FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    Field,
    PageHeader,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';

interface OrganizationPayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    email_domains: string[];
    contact_email: string | null;
    phone: string | null;
    is_active: boolean;
    shared_ticket_visibility: boolean;
}

export default function OrganizationForm({ organization }: { organization: OrganizationPayload | null }) {
    const { t } = useTranslations();
    const isEdit = organization !== null;

    const form = useForm({
        name: organization?.name ?? '',
        slug: organization?.slug ?? '',
        description: organization?.description ?? '',
        email_domains: organization?.email_domains ?? ([] as string[]),
        contact_email: organization?.contact_email ?? '',
        phone: organization?.phone ?? '',
        is_active: organization?.is_active ?? true,
        shared_ticket_visibility: organization?.shared_ticket_visibility ?? false,
    });

    useEffect(() => {
        if (!isEdit) {
            form.setData('slug', slugify(form.data.name));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.name]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/organizations/${organization.id}`);
        } else {
            form.post('/admin/organizations');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.organizations.edit') : t('admin.organizations.create')}>
            <PageHeader
                title={isEdit ? t('admin.organizations.edit') : t('admin.organizations.create')}
                description={t('admin.organizations.subtitle')}
                actions={
                    <ButtonLink href="/admin/organizations" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="mx-auto max-w-2xl">
                <Card>
                    <CardBody className="space-y-4">
                        <Field label={t('common.labels.name')} error={form.errors.name} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.name}
                                    required
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.organizations.fields.slug')} error={form.errors.slug} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.slug}
                                    required
                                    onChange={(event) => form.setData('slug', slugify(event.target.value))}
                                />
                            )}
                        </Field>

                        <Field label={t('common.labels.description')} error={form.errors.description}>
                            {(props) => (
                                <Textarea
                                    {...props}
                                    rows={2}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.organizations.fields.email_domains')}
                            help={t('admin.organizations.fields.email_domains_help')}
                            error={form.errors.email_domains}
                        >
                            {(props) => (
                                <Textarea
                                    {...props}
                                    rows={3}
                                    value={form.data.email_domains.join('\n')}
                                    placeholder={'example.org\nexample.nl'}
                                    onChange={(event) =>
                                        form.setData(
                                            'email_domains',
                                            event.target.value
                                                .split('\n')
                                                .map((line) => line.trim().toLowerCase())
                                                .filter(Boolean),
                                        )
                                    }
                                />
                            )}
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('admin.organizations.fields.contact_email')}
                                error={form.errors.contact_email}
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="email"
                                        value={form.data.contact_email}
                                        onChange={(event) => form.setData('contact_email', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field label={t('admin.organizations.fields.phone')} error={form.errors.phone}>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.phone}
                                        onChange={(event) => form.setData('phone', event.target.value)}
                                    />
                                )}
                            </Field>
                        </div>

                        <Toggle
                            checked={form.data.shared_ticket_visibility}
                            onChange={(value) => form.setData('shared_ticket_visibility', value)}
                            label={t('admin.organizations.fields.shared_visibility')}
                            description={t('admin.organizations.fields.shared_visibility_help')}
                        />

                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('admin.organizations.fields.active')}
                        />
                    </CardBody>
                    <CardFooter>
                        <ButtonLink href="/admin/organizations" variant="secondary">
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
