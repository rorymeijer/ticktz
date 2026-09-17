import { useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconPlus, IconTrash } from '@/Components/Icons';
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
import { slugify } from '@/lib/slug';
import type { PrioritySummary } from '@/types/tickets';
import { RichTextField } from '@/Components/RichText/RichTextField';

interface CustomFieldOption {
    id: number;
    key: string;
    label: string;
    type: string;
    is_required: boolean;
}

interface FormFieldRow {
    custom_field_id: number;
    is_required: boolean | null;
    help_text: string | null;
}

interface RequestTypePayload {
    id: number;
    portal_category_id: number | null;
    name: string;
    name_translations: Record<string, string>;
    slug: string;
    description: string | null;
    description_translations: Record<string, string>;
    instructions: string | null;
    instructions_translations: Record<string, string>;
    icon: string | null;
    queue_id: number | null;
    team_id: number | null;
    workflow_id: number | null;
    approval_workflow_id: number | null;
    priority_id: number | null;
    allow_priority_choice: boolean;
    subject_template: string | null;
    visibility: 'everyone' | 'organizations' | 'agents';
    organization_ids: number[];
    is_active: boolean;
    position: number;
    fields: FormFieldRow[];
}

interface NamedOption {
    id: number;
    name: string;
}

export default function RequestTypeForm({
    requestType,
    categories,
    queues,
    teams,
    workflows,
    approvalWorkflows,
    priorities,
    organizations,
    customFields,
}: {
    requestType: RequestTypePayload | null;
    categories: NamedOption[];
    queues: NamedOption[];
    teams: NamedOption[];
    workflows: NamedOption[];
    approvalWorkflows: NamedOption[];
    priorities: PrioritySummary[];
    organizations: NamedOption[];
    customFields: CustomFieldOption[];
}) {
    const { t, locales } = useTranslations();
    const isEdit = requestType !== null;

    const form = useForm<{
        portal_category_id: string;
        name: string;
        name_translations: Record<string, string>;
        slug: string;
        description: string;
        description_translations: Record<string, string>;
        instructions: string;
        instructions_translations: Record<string, string>;
        queue_id: string;
        team_id: string;
        workflow_id: string;
        approval_workflow_id: string;
        priority_id: string;
        allow_priority_choice: boolean;
        subject_template: string;
        visibility: 'everyone' | 'organizations' | 'agents';
        organization_ids: number[];
        is_active: boolean;
        position: number;
        fields: FormFieldRow[];
    }>({
        portal_category_id: requestType?.portal_category_id ? String(requestType.portal_category_id) : '',
        name: requestType?.name ?? '',
        name_translations: requestType?.name_translations ?? {},
        slug: requestType?.slug ?? '',
        description: requestType?.description ?? '',
        description_translations: requestType?.description_translations ?? {},
        instructions: requestType?.instructions ?? '',
        instructions_translations: requestType?.instructions_translations ?? {},
        queue_id: requestType?.queue_id ? String(requestType.queue_id) : '',
        team_id: requestType?.team_id ? String(requestType.team_id) : '',
        workflow_id: requestType?.workflow_id ? String(requestType.workflow_id) : '',
        approval_workflow_id: requestType?.approval_workflow_id
            ? String(requestType.approval_workflow_id)
            : '',
        priority_id: requestType?.priority_id ? String(requestType.priority_id) : '',
        allow_priority_choice: requestType?.allow_priority_choice ?? false,
        subject_template: requestType?.subject_template ?? '',
        visibility: requestType?.visibility ?? 'everyone',
        organization_ids: requestType?.organization_ids ?? [],
        is_active: requestType?.is_active ?? true,
        position: requestType?.position ?? 100,
        fields: requestType?.fields ?? [],
    });

    const available = customFields.filter(
        (field) => !form.data.fields.some((row) => row.custom_field_id === field.id),
    );

    const move = (index: number, delta: number) => {
        const next = [...form.data.fields];
        const target = index + delta;

        if (target < 0 || target >= next.length) return;

        [next[index], next[target]] = [next[target], next[index]];
        form.setData('fields', next);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/request-types/${requestType.id}`);
        } else {
            form.post('/admin/request-types');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.request_types.edit') : t('admin.request_types.create')}>
            <PageHeader
                title={isEdit ? t('admin.request_types.edit') : t('admin.request_types.create')}
                description={t('admin.request_types.subtitle')}
                actions={
                    <ButtonLink href="/admin/request-types" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader title={t('admin.request_types.sections.presentation')} />
                    <CardBody className="space-y-4">
                        <Field label={t('common.labels.name')} error={form.errors.name} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.name}
                                    required
                                    onChange={(event) => {
                                        form.setData('name', event.target.value);
                                        if (!isEdit) form.setData('slug', slugify(event.target.value));
                                    }}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.request_types.fields.slug')} error={form.errors.slug} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.slug}
                                    required
                                    className="font-mono"
                                    onChange={(event) => form.setData('slug', slugify(event.target.value))}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.request_types.fields.category')} error={form.errors.portal_category_id}>
                            {(props) => (
                                <Select
                                    {...props}
                                    value={form.data.portal_category_id}
                                    onChange={(event) => form.setData('portal_category_id', event.target.value)}
                                >
                                    <option value="">{t('common.labels.none')}</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>

                        <Field label={t('admin.request_types.fields.description')} error={form.errors.description}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                            )}
                        </Field>

                        <RichTextField
                            label={t('admin.request_types.fields.instructions')}
                            help={t('admin.request_types.fields.instructions_help')}
                            error={form.errors.instructions}
                            value={form.data.instructions}
                            onChange={(html) => form.setData('instructions', html)}
                            minHeight="7rem"
                        />

                        {locales
                            .filter((locale) => locale.code !== 'en')
                            .map((locale) => (
                                <div key={locale.code} className="space-y-3 rounded-lg border border-slate-200 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {t('admin.request_types.fields.translations')} — {locale.native}
                                    </p>

                                    <Field label={t('common.labels.name')}>
                                        {(props) => (
                                            <TextInput
                                                {...props}
                                                value={form.data.name_translations[locale.code] ?? ''}
                                                onChange={(event) =>
                                                    form.setData('name_translations', {
                                                        ...form.data.name_translations,
                                                        [locale.code]: event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </Field>

                                    <Field label={t('admin.request_types.fields.description')}>
                                        {(props) => (
                                            <TextInput
                                                {...props}
                                                value={form.data.description_translations[locale.code] ?? ''}
                                                onChange={(event) =>
                                                    form.setData('description_translations', {
                                                        ...form.data.description_translations,
                                                        [locale.code]: event.target.value,
                                                    })
                                                }
                                            />
                                        )}
                                    </Field>

                                    <RichTextField
                                        label={t('admin.request_types.fields.instructions')}
                                        minHeight="6rem"
                                        value={form.data.instructions_translations[locale.code] ?? ''}
                                        onChange={(html) =>
                                            form.setData('instructions_translations', {
                                                ...form.data.instructions_translations,
                                                [locale.code]: html,
                                            })
                                        }
                                    />
                                </div>
                            ))}

                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('admin.request_types.fields.is_active')}
                        />
                    </CardBody>
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title={t('admin.request_types.sections.routing')} />
                        <CardBody className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('admin.request_types.fields.queue')} error={form.errors.queue_id}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.queue_id}
                                            onChange={(event) => form.setData('queue_id', event.target.value)}
                                        >
                                            <option value="">{t('common.labels.none')}</option>
                                            {queues.map((queue) => (
                                                <option key={queue.id} value={queue.id}>
                                                    {queue.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field label={t('admin.request_types.fields.team')} error={form.errors.team_id}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.team_id}
                                            onChange={(event) => form.setData('team_id', event.target.value)}
                                        >
                                            <option value="">{t('common.labels.none')}</option>
                                            {teams.map((team) => (
                                                <option key={team.id} value={team.id}>
                                                    {team.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={t('admin.request_types.fields.workflow')} error={form.errors.workflow_id}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.workflow_id}
                                            onChange={(event) => form.setData('workflow_id', event.target.value)}
                                        >
                                            <option value="">{t('common.labels.default')}</option>
                                            {workflows.map((workflow) => (
                                                <option key={workflow.id} value={workflow.id}>
                                                    {workflow.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field
                                    label={t('approvals.fields.workflow')}
                                    error={form.errors.approval_workflow_id}
                                    help={t('admin.request_types.fields.approval_help')}
                                >
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.approval_workflow_id}
                                            onChange={(event) =>
                                                form.setData('approval_workflow_id', event.target.value)
                                            }
                                        >
                                            <option value="">{t('common.labels.none')}</option>
                                            {approvalWorkflows.map((workflow) => (
                                                <option key={workflow.id} value={workflow.id}>
                                                    {workflow.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>

                                <Field label={t('admin.request_types.fields.priority')} error={form.errors.priority_id}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.priority_id}
                                            onChange={(event) => form.setData('priority_id', event.target.value)}
                                        >
                                            <option value="">{t('common.labels.default')}</option>
                                            {priorities.map((priority) => (
                                                <option key={priority.id} value={priority.id}>
                                                    {priority.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            </div>

                            <Toggle
                                checked={form.data.allow_priority_choice}
                                onChange={(value) => form.setData('allow_priority_choice', value)}
                                label={t('admin.request_types.fields.allow_priority_choice')}
                            />

                            <Field
                                label={t('admin.request_types.fields.subject_template')}
                                help={t('admin.request_types.fields.subject_template_help')}
                                error={form.errors.subject_template}
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.subject_template}
                                        className="font-mono text-xs"
                                        placeholder={t('admin.request_types.fields.subject_template_placeholder')}
                                        onChange={(event) => form.setData('subject_template', event.target.value)}
                                    />
                                )}
                            </Field>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title={t('admin.request_types.sections.access')} />
                        <CardBody className="space-y-4">
                            <Field label={t('admin.request_types.fields.visibility')} error={form.errors.visibility}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.visibility}
                                        onChange={(event) =>
                                            form.setData(
                                                'visibility',
                                                event.target.value as RequestTypePayload['visibility'],
                                            )
                                        }
                                    >
                                        <option value="everyone">
                                            {t('admin.request_types.visibility.everyone')}
                                        </option>
                                        <option value="organizations">
                                            {t('admin.request_types.visibility.organizations')}
                                        </option>
                                        <option value="agents">{t('admin.request_types.visibility.agents')}</option>
                                    </Select>
                                )}
                            </Field>

                            {form.data.visibility === 'organizations' ? (
                                <CheckboxGroup
                                    options={organizations.map((organization) => ({
                                        value: organization.id,
                                        label: organization.name,
                                    }))}
                                    selected={form.data.organization_ids}
                                    onChange={(values) => form.setData('organization_ids', values)}
                                    maxHeight="max-h-48"
                                    emptyLabel={t('admin.organizations.empty')}
                                />
                            ) : null}
                        </CardBody>
                    </Card>
                </div>

                <div className="lg:col-span-2">
                    <Card>
                        <CardHeader
                            title={t('admin.request_types.fields.form_fields')}
                            description={t('admin.request_types.fields.form_fields_help')}
                        />
                        <CardBody className="space-y-3">
                            {form.data.fields.length === 0 ? (
                                <p className="text-sm text-slate-500">{t('common.empty.description')}</p>
                            ) : (
                                <ol className="space-y-2">
                                    {form.data.fields.map((row, index) => {
                                        const definition = customFields.find(
                                            (field) => field.id === row.custom_field_id,
                                        );

                                        return (
                                            <li
                                                key={row.custom_field_id}
                                                className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2.5"
                                            >
                                                <div className="flex flex-col">
                                                    <button
                                                        type="button"
                                                        aria-label={t('common.actions.previous')}
                                                        onClick={() => move(index, -1)}
                                                        className="px-1 text-xs text-slate-500 hover:text-slate-700"
                                                    >
                                                        ▲
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label={t('common.actions.next')}
                                                        onClick={() => move(index, 1)}
                                                        className="px-1 text-xs text-slate-500 hover:text-slate-700"
                                                    >
                                                        ▼
                                                    </button>
                                                </div>

                                                <div className="min-w-40 flex-1">
                                                    <p className="text-sm font-medium text-slate-800">
                                                        {definition?.label ?? row.custom_field_id}
                                                    </p>
                                                    <code className="font-mono text-[11px] text-slate-500">
                                                        {definition?.key}
                                                    </code>
                                                </div>

                                                <Select
                                                    aria-label={t('admin.request_types.fields.required_override')}
                                                    value={row.is_required === null ? '' : row.is_required ? '1' : '0'}
                                                    className="w-52"
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'fields',
                                                            form.data.fields.map((item, i) =>
                                                                i === index
                                                                    ? {
                                                                          ...item,
                                                                          is_required:
                                                                              event.target.value === ''
                                                                                  ? null
                                                                                  : event.target.value === '1',
                                                                      }
                                                                    : item,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        {t('admin.request_types.fields.required_inherit')}
                                                    </option>
                                                    <option value="1">
                                                        {t('admin.request_types.fields.required_yes')}
                                                    </option>
                                                    <option value="0">
                                                        {t('admin.request_types.fields.required_no')}
                                                    </option>
                                                </Select>

                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={t('common.actions.remove')}
                                                    onClick={() =>
                                                        form.setData(
                                                            'fields',
                                                            form.data.fields.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    <IconTrash />
                                                </Button>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}

                            {available.length > 0 ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select
                                        aria-label={t('admin.request_types.fields.add_field')}
                                        value=""
                                        className="max-w-xs"
                                        onChange={(event) => {
                                            if (!event.target.value) return;

                                            form.setData('fields', [
                                                ...form.data.fields,
                                                {
                                                    custom_field_id: Number(event.target.value),
                                                    is_required: null,
                                                    help_text: null,
                                                },
                                            ]);
                                        }}
                                    >
                                        <option value="">{t('admin.request_types.fields.add_field')}</option>
                                        {available.map((field) => (
                                            <option key={field.id} value={field.id}>
                                                {field.label} ({t(`admin.custom_fields.types.${field.type}`)})
                                            </option>
                                        ))}
                                    </Select>

                                    <ButtonLink href="/admin/custom-fields" variant="ghost" size="sm" icon={<IconPlus />}>
                                        {t('admin.custom_fields.add')}
                                    </ButtonLink>
                                </div>
                            ) : null}
                        </CardBody>

                        <CardFooter>
                            <ButtonLink href="/admin/request-types" variant="secondary">
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
