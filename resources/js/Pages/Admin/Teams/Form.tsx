import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEventHandler } from 'react';

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
import { PersonMultiPicker } from '@/Components/UI/PersonPicker';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';
import type { UserSummary } from '@/types/tickets';

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
    people,
}: {
    team: TeamPayload | null;
    /** Only the people this team already has — the rest are searched for. */
    people: UserSummary[];
}) {
    const { t } = useTranslations();
    const isEdit = team !== null;

    // The form posts ids; the picker shows people. This holds the second half
    // for the members, and the leads list is drawn from it — a team's own
    // membership is a short list by construction, so that one stays a set of
    // checkboxes rather than a second search box.
    const [members, setMembers] = useState<UserSummary[]>(people);

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

    const leadOptions = members.map((member) => ({
        value: member.id,
        label: member.name,
        description: member.email,
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
                            <PersonMultiPicker
                                value={members}
                                query={{ scope: 'agent' }}
                                emptyLabel={t('common.labels.none')}
                                onChange={(chosen) => {
                                    setMembers(chosen);

                                    const ids = chosen.map((person) => person.id);

                                    form.setData((data) => ({
                                        ...data,
                                        member_ids: ids,
                                        // Somebody taken off the team cannot
                                        // stay one of its leads.
                                        lead_ids: data.lead_ids.filter((id) => ids.includes(id)),
                                    }));
                                }}
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
                                options={leadOptions}
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
