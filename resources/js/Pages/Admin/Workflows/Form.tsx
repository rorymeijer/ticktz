import { useForm } from '@inertiajs/react';
import { useMemo, type FormEventHandler } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import {
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    Checkbox,
    CheckboxGroup,
    Field,
    PageHeader,
    Select,
    TextInput,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { slugify } from '@/lib/slug';
import type { StatusSummary } from '@/types/tickets';

interface TransitionRow {
    from_status_id: number | null;
    to_status_id: number;
    requires_comment: boolean;
    requires_assignee: boolean;
    requires_approval: boolean;
    required_permission: string | null;
}

interface WorkflowPayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_default: boolean;
    is_active: boolean;
    status_ids: number[];
    initial_status_id: number | null;
    transitions: TransitionRow[];
}

/**
 * The workflow editor renders transitions as a matrix: rows are the status you
 * are in, columns the status you may move to. A six-status process has thirty
 * possible moves — a list of them is unreadable, a grid is obvious.
 */
export default function WorkflowForm({
    workflow,
    statuses,
}: {
    workflow: WorkflowPayload | null;
    statuses: StatusSummary[];
}) {
    const { t } = useTranslations();
    const isEdit = workflow !== null;

    const form = useForm<{
        name: string;
        slug: string;
        description: string;
        is_default: boolean;
        is_active: boolean;
        status_ids: number[];
        initial_status_id: number | null;
        transitions: TransitionRow[];
    }>({
        name: workflow?.name ?? '',
        slug: workflow?.slug ?? '',
        description: workflow?.description ?? '',
        is_default: workflow?.is_default ?? false,
        is_active: workflow?.is_active ?? true,
        status_ids: workflow?.status_ids ?? [],
        initial_status_id: workflow?.initial_status_id ?? null,
        transitions: workflow?.transitions ?? [],
    });

    const selected = useMemo(
        () => statuses.filter((status) => form.data.status_ids.includes(status.id)),
        [statuses, form.data.status_ids],
    );

    // NULL means "from any status" — the universal transitions a workflow can
    // carry, such as a Close that works from anywhere.
    const nameOf = (id: number | null) =>
        id === null
            ? t('common.labels.all')
            : (statuses.find((status) => status.id === id)?.name ?? String(id));

    const has = (from: number, to: number) =>
        form.data.transitions.some(
            (transition) => transition.from_status_id === from && transition.to_status_id === to,
        );

    const toggle = (from: number, to: number) => {
        form.setData(
            'transitions',
            has(from, to)
                ? form.data.transitions.filter(
                      (transition) => !(transition.from_status_id === from && transition.to_status_id === to),
                  )
                : [
                      ...form.data.transitions,
                      {
                          from_status_id: from,
                          to_status_id: to,
                          requires_comment: false,
                          requires_assignee: false,
                          requires_approval: false,
                          required_permission: null,
                      },
                  ],
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/workflows/${workflow.id}`);
        } else {
            form.post('/admin/workflows');
        }
    };

    return (
        <AdminLayout title={isEdit ? t('admin.workflows.edit') : t('admin.workflows.create')}>
            <PageHeader
                title={isEdit ? t('admin.workflows.edit') : t('admin.workflows.create')}
                description={t('admin.workflows.subtitle')}
                actions={
                    <ButtonLink href="/admin/workflows" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="space-y-5">
                <Card>
                    <CardBody className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
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

                            <Field label={t('admin.workflows.fields.slug')} error={form.errors.slug} required>
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

                        <Toggle
                            checked={form.data.is_default}
                            onChange={(value) => form.setData('is_default', value)}
                            label={t('admin.workflows.fields.is_default')}
                        />

                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('admin.workflows.fields.is_active')}
                        />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('admin.workflows.fields.statuses')} />
                    <CardBody className="space-y-4">
                        <CheckboxGroup
                            options={statuses.map((status) => ({ value: status.id, label: status.name }))}
                            selected={form.data.status_ids}
                            columns={2}
                            onChange={(values) =>
                                form.setData((data) => ({
                                    ...data,
                                    status_ids: values,
                                    initial_status_id: values.includes(data.initial_status_id ?? -1)
                                        ? data.initial_status_id
                                        : (values[0] ?? null),
                                    transitions: data.transitions.filter(
                                        (transition) =>
                                            values.includes(transition.to_status_id) &&
                                            (transition.from_status_id === null ||
                                                values.includes(transition.from_status_id)),
                                    ),
                                }))
                            }
                        />

                        <Field
                            label={t('admin.workflows.fields.initial_status')}
                            error={form.errors.initial_status_id}
                            required
                        >
                            {(props) => (
                                <Select
                                    {...props}
                                    value={String(form.data.initial_status_id ?? '')}
                                    onChange={(event) => form.setData('initial_status_id', Number(event.target.value))}
                                >
                                    {selected.map((status) => (
                                        <option key={status.id} value={status.id}>
                                            {status.name}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title={t('admin.workflows.fields.transitions')}
                        description={t('admin.workflows.fields.transitions_help')}
                    />
                    <CardBody>
                        {selected.length === 0 ? (
                            <p className="text-sm text-slate-500">{t('common.empty.description')}</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse text-sm">
                                    <thead>
                                        <tr>
                                            <th className="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold text-slate-500">
                                                {t('admin.workflows.matrix.from')}
                                            </th>
                                            {selected.map((status) => (
                                                <th
                                                    key={status.id}
                                                    className="border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600"
                                                >
                                                    {status.name}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selected.map((from) => (
                                            <tr key={from.id}>
                                                <th
                                                    scope="row"
                                                    className="border border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-medium text-slate-600"
                                                >
                                                    {from.name}
                                                </th>
                                                {selected.map((to) => (
                                                    <td
                                                        key={to.id}
                                                        className={cn(
                                                            'border border-slate-200 text-center',
                                                            from.id === to.id && 'bg-slate-100',
                                                        )}
                                                    >
                                                        {from.id === to.id ? (
                                                            <span aria-hidden="true" className="text-slate-300">
                                                                —
                                                            </span>
                                                        ) : (
                                                            <label className="flex cursor-pointer items-center justify-center p-2">
                                                                <span className="sr-only">
                                                                    {from.name} → {to.name}
                                                                </span>
                                                                <input
                                                                    type="checkbox"
                                                                    checked={has(from.id, to.id)}
                                                                    onChange={() => toggle(from.id, to.id)}
                                                                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                                                />
                                                            </label>
                                                        )}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardBody>

                    {/* The matrix says which moves are legal; this says what
                        each of them demands first. Kept as a list under the
                        grid rather than as more columns in it — a matrix with
                        three checkboxes per cell is unreadable at six
                        statuses, which is the size a real workflow starts at. */}
                    {form.data.transitions.length > 0 ? (
                        <CardBody className="border-t border-slate-200">
                            <p className="text-sm font-medium text-slate-800">
                                {t('admin.workflows.fields.conditions')}
                            </p>
                            <p className="mb-3 text-xs text-slate-500">
                                {t('admin.workflows.fields.conditions_help')}
                            </p>

                            <ul className="divide-y divide-slate-100">
                                {form.data.transitions.map((transition, index) => (
                                    <li
                                        key={`${transition.from_status_id}-${transition.to_status_id}`}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-2"
                                    >
                                        <span className="min-w-48 flex-1 text-sm text-slate-700">
                                            {nameOf(transition.from_status_id)}
                                            <span aria-hidden="true" className="mx-1.5 text-slate-300">
                                                →
                                            </span>
                                            {nameOf(transition.to_status_id)}
                                        </span>

                                        {(
                                            [
                                                ['requires_comment', 'requires_comment'],
                                                ['requires_assignee', 'requires_assignee'],
                                                ['requires_approval', 'requires_approval'],
                                            ] as const
                                        ).map(([field, label]) => (
                                            <label
                                                key={field}
                                                className="inline-flex items-center gap-1.5 text-xs text-slate-600"
                                            >
                                                <Checkbox
                                                    checked={transition[field]}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'transitions',
                                                            form.data.transitions.map((row, i) =>
                                                                i === index
                                                                    ? { ...row, [field]: event.target.checked }
                                                                    : row,
                                                            ),
                                                        )
                                                    }
                                                />
                                                {t(
                                                    label === 'requires_approval'
                                                        ? 'approvals.fields.requires_approval'
                                                        : `admin.workflows.fields.${label}`,
                                                )}
                                            </label>
                                        ))}
                                    </li>
                                ))}
                            </ul>

                            <p className="mt-2 text-xs text-slate-500">
                                {t('approvals.fields.requires_approval_help')}
                            </p>
                        </CardBody>
                    ) : null}

                    <CardFooter>
                        <ButtonLink href="/admin/workflows" variant="secondary">
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
