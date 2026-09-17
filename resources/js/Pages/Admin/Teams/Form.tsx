import { useForm } from '@inertiajs/react';
import { useEffect, type FormEventHandler } from 'react';

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
    TextInput,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';

interface TeamPayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    email: string | null;
    is_active: boolean;
    member_ids: number[];
    lead_ids: number[];
}

export default function TeamForm({
    team,
    agents,
}: {
    team: TeamPayload | null;
    agents: { id: number; name: string; email: string }[];
}) {
    const { t } = useTranslations();
    const isEdit = team !== null;

    const form = useForm({
        name: team?.name ?? '',
        slug: team?.slug ?? '',
        description: team?.description ?? '',
        email: team?.email ?? '',
        is_active: team?.is_active ?? true,
        member_ids: team?.member_ids ?? [],
        lead_ids: team?.lead_ids ?? [],
    });

    // Derive the slug while creating; once a team exists its slug is stable so
    // saved queue filters and API references keep working.
    useEffect(() => {
        if (!isEdit) {
            form.setData('slug', slugify(form.data.name));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.name]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/teams/${team.id}`);
        } else {
            form.post('/admin/teams');
        }
    };

    const memberOptions = agents.map((agent) => ({
        value: agent.id,
        label: agent.name,
        description: agent.email,
    }));

    return (
        <AdminLayout title={isEdit ? t('admin.teams.edit') : t('admin.teams.create')}>
            <PageHeader
                title={isEdit ? t('admin.teams.edit') : t('admin.teams.create')}
                description={t('admin.teams.subtitle')}
                actions={
                    <ButtonLink href="/admin/teams" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader title={t('admin.teams.columns.team')} />
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

                        <Field label={t('admin.teams.fields.slug')} error={form.errors.slug} required>
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
                                <TextInput
                                    {...props}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.teams.fields.email')}
                            help={t('admin.teams.fields.email_help')}
                            error={form.errors.email}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="email"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            )}
                        </Field>

                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('admin.teams.fields.active')}
                        />
                    </CardBody>
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title={t('admin.teams.fields.members')} />
                        <CardBody>
                            <CheckboxGroup
                                options={memberOptions}
                                selected={form.data.member_ids}
                                onChange={(values) =>
                                    form.setData((data) => ({
                                        ...data,
                                        member_ids: values,
                                        lead_ids: data.lead_ids.filter((id) => values.includes(id)),
                                    }))
                                }
                            />
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader
                            title={t('admin.teams.fields.leads')}
                            description={t('admin.teams.fields.leads_help')}
                        />
                        <CardBody>
                            <CheckboxGroup
                                options={memberOptions.filter((option) => form.data.member_ids.includes(option.value))}
                                selected={form.data.lead_ids}
                                onChange={(values) => form.setData('lead_ids', values)}
                                emptyLabel={t('common.labels.none')}
                                maxHeight="max-h-48"
                            />
                        </CardBody>
                    </Card>
                </div>

                <div className="lg:col-span-2">
                    <Card>
                        <CardFooter>
                            <ButtonLink href="/admin/teams" variant="secondary">
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
