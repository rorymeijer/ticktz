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
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';
import type { LabelSummary, PrioritySummary, StatusSummary } from '@/types/tickets';

interface QueueFilters {
    status_category?: string[];
    status?: number[];
    priority?: number[];
    assignee?: string[];
    team?: string[];
    label?: number[];
    unanswered?: boolean;
}

interface QueuePayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    team_id: number | null;
    filters: QueueFilters;
    sort_by: string;
    sort_direction: 'asc' | 'desc';
    columns: string[];
    is_shared: boolean;
    is_active: boolean;
    position: number;
}

const CATEGORIES = ['new', 'open', 'pending', 'resolved', 'closed'];

export default function QueueForm({
    queue,
    teams,
    statuses,
    priorities,
    labels,
    availableColumns,
    sortableColumns,
}: {
    queue: QueuePayload | null;
    teams: { id: number; name: string }[];
    statuses: StatusSummary[];
    priorities: PrioritySummary[];
    labels: LabelSummary[];
    availableColumns: string[];
    sortableColumns: string[];
}) {
    const { t } = useTranslations();
    const isEdit = queue !== null;

    const form = useForm<{
        name: string;
        slug: string;
        description: string;
        team_id: string;
        filters: QueueFilters;
        sort_by: string;
        sort_direction: 'asc' | 'desc';
        columns: string[];
        is_shared: boolean;
        is_active: boolean;
        position: number;
    }>({
        name: queue?.name ?? '',
        slug: queue?.slug ?? '',
        description: queue?.description ?? '',
        team_id: queue?.team_id ? String(queue.team_id) : '',
        filters: queue?.filters ?? {},
        sort_by: queue?.sort_by ?? 'updated_at',
        sort_direction: queue?.sort_direction ?? 'desc',
        columns: queue?.columns ?? ['key', 'subject', 'status', 'priority', 'requester', 'assignee', 'updated_at'],
        is_shared: queue?.is_shared ?? true,
        is_active: queue?.is_active ?? true,
        position: queue?.position ?? 100,
    });

    const setFilter = <K extends keyof QueueFilters>(key: K, value: QueueFilters[K]) => {
        form.setData('filters', { ...form.data.filters, [key]: value });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/queues/${queue.id}`);
        } else {
            form.post('/admin/queues');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.queues.edit') : t('admin.queues.create')}>
            <PageHeader
                title={isEdit ? t('admin.queues.edit') : t('admin.queues.create')}
                description={t('admin.queues.subtitle')}
                actions={
                    <ButtonLink href="/admin/queues" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader title={t('admin.queues.columns.queue')} />
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

                        <Field label={t('admin.queues.fields.slug')} error={form.errors.slug} required>
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
                            label={t('admin.queues.fields.team')}
                            help={t('admin.queues.fields.team_help')}
                            error={form.errors.team_id}
                        >
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

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('admin.queues.fields.sort_by')}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.sort_by}
                                        onChange={(event) => form.setData('sort_by', event.target.value)}
                                    >
                                        {sortableColumns.map((column) => (
                                            <option key={column} value={column}>
                                                {t(`tickets.columns.${column.replace('_id', '')}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('admin.queues.fields.sort_direction')}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.sort_direction}
                                        onChange={(event) =>
                                            form.setData('sort_direction', event.target.value as 'asc' | 'desc')
                                        }
                                    >
                                        <option value="desc">{t('admin.queues.sort.desc')}</option>
                                        <option value="asc">{t('admin.queues.sort.asc')}</option>
                                    </Select>
                                )}
                            </Field>
                        </div>

                        <Toggle
                            checked={form.data.is_shared}
                            onChange={(value) => form.setData('is_shared', value)}
                            label={t('admin.queues.fields.is_shared')}
                        />

                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('admin.queues.fields.is_active')}
                        />
                    </CardBody>
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title={t('admin.queues.fields.filters')} />
                        <CardBody className="space-y-4">
                            <div>
                                <p className="mb-1.5 text-sm font-medium text-slate-700">
                                    {t('admin.queues.fields.status_category')}
                                </p>
                                <CheckboxGroup
                                    options={CATEGORIES.map((category) => ({
                                        value: category,
                                        label: t(`tickets.status_category.${category}`),
                                    }))}
                                    selected={form.data.filters.status_category ?? []}
                                    columns={2}
                                    maxHeight="max-h-40"
                                    onChange={(values) => setFilter('status_category', values)}
                                />
                            </div>

                            <div>
                                <p className="mb-1.5 text-sm font-medium text-slate-700">
                                    {t('admin.queues.fields.priorities')}
                                </p>
                                <CheckboxGroup
                                    options={priorities.map((priority) => ({
                                        value: priority.id,
                                        label: priority.name,
                                    }))}
                                    selected={form.data.filters.priority ?? []}
                                    columns={2}
                                    maxHeight="max-h-32"
                                    onChange={(values) => setFilter('priority', values)}
                                />
                            </div>

                            <div>
                                <p className="mb-1.5 text-sm font-medium text-slate-700">
                                    {t('admin.queues.fields.assignee')}
                                </p>
                                <CheckboxGroup
                                    options={[
                                        { value: 'unassigned', label: t('admin.queues.assignee_options.unassigned') },
                                        { value: 'me', label: t('admin.queues.assignee_options.me') },
                                    ]}
                                    selected={form.data.filters.assignee ?? []}
                                    maxHeight="max-h-24"
                                    onChange={(values) => setFilter('assignee', values)}
                                />
                            </div>

                            {labels.length > 0 ? (
                                <div>
                                    <p className="mb-1.5 text-sm font-medium text-slate-700">
                                        {t('admin.queues.fields.labels')}
                                    </p>
                                    <CheckboxGroup
                                        options={labels.map((label) => ({ value: label.id, label: label.name }))}
                                        selected={form.data.filters.label ?? []}
                                        columns={2}
                                        maxHeight="max-h-32"
                                        onChange={(values) => setFilter('label', values)}
                                    />
                                </div>
                            ) : null}

                            <Toggle
                                checked={Boolean(form.data.filters.unanswered)}
                                onChange={(value) => setFilter('unanswered', value)}
                                label={t('admin.queues.fields.unanswered')}
                            />
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title={t('admin.queues.fields.columns')} />
                        <CardBody>
                            <CheckboxGroup
                                options={availableColumns.map((column) => ({
                                    value: column,
                                    label: t(`tickets.columns.${column}`),
                                }))}
                                selected={form.data.columns}
                                columns={2}
                                maxHeight="max-h-56"
                                onChange={(values) => form.setData('columns', values)}
                            />
                        </CardBody>
                        <CardFooter>
                            <ButtonLink href="/admin/queues" variant="secondary">
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
