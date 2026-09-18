import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { TranslationsField } from '@/Components/Admin/TranslationsField';
import { IconPlus, IconTrash, IconX } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Checkbox,
    CheckboxGroup,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Select,
    TextInput,
    Textarea,
} from '@/Components/UI';
import { PersonMultiPicker } from '@/Components/UI/PersonPicker';
import { useTranslations } from '@/hooks/useTranslations';
import type { UserSummary } from '@/types/tickets';
import { RichTextField } from '@/Components/RichText/RichTextField';
import type {
    ApprovalAdminOptions,
    ApprovalWorkflowAdmin,
    ApprovalWorkflowStep,
    ApproverType,
} from '@/types/approvals';

/** A step as the form holds it — no id, because steps are replaced wholesale. */
type DraftStep = Omit<ApprovalWorkflowStep, 'id' | 'label' | 'position'>;

const emptyStep: DraftStep = {
    name: null,
    mode: 'any',
    approver_type: 'users',
    approver_ids: [],
    approver_field: null,
    due_hours: null,
};

export default function AdminApprovalsIndex({
    workflows,
    options,
}: {
    workflows: ApprovalWorkflowAdmin[];
    options: ApprovalAdminOptions;
}) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<ApprovalWorkflowAdmin | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<ApprovalWorkflowAdmin | null>(null);

    return (
        <AdminLayout
            title={t('approvals.admin.title')}
            actions={
                <Button icon={<IconPlus className="h-4 w-4" />} onClick={() => setCreating(true)}>
                    {t('approvals.admin.add')}
                </Button>
            }
        >
            <PageHeader title={t('approvals.admin.title')} description={t('approvals.admin.subtitle')} />

            {workflows.length > 0 ? (
                <div className="space-y-3">
                    {workflows.map((workflow) => (
                        <Card key={workflow.id}>
                            <CardHeader
                                title={workflow.name}
                                description={workflow.description ?? undefined}
                                actions={
                                    <div className="flex items-center gap-1.5">
                                        <Badge tone="blue">{t(`approvals.shape.${workflow.shape}`)}</Badge>
                                        {!workflow.is_active ? (
                                            <Badge tone="slate">{t('common.labels.inactive')}</Badge>
                                        ) : null}
                                        <Button size="sm" variant="ghost" onClick={() => setEditing(workflow)}>
                                            {t('common.actions.edit')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label={t('common.actions.delete')}
                                            onClick={() => setDeleting(workflow)}
                                        >
                                            <IconTrash className="h-4 w-4 text-slate-500" />
                                        </Button>
                                    </div>
                                }
                            />
                            <CardBody>
                                {workflow.steps.length > 0 ? (
                                    <ol className="space-y-1.5">
                                        {workflow.steps.map((step) => (
                                            <li
                                                key={step.id}
                                                className="flex flex-wrap items-center gap-2 text-sm text-slate-600"
                                            >
                                                <span className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600">
                                                    {step.position + 1}
                                                </span>
                                                <span className="font-medium text-slate-800">{step.label}</span>
                                                <span className="text-slate-500">
                                                    {t(`approvals.approver_type.${step.approver_type}`)}
                                                </span>
                                                <Badge tone="slate">{t(`approvals.mode.${step.mode}_short`)}</Badge>
                                                {step.due_hours ? (
                                                    <span className="text-xs text-slate-500">
                                                        {t('approvals.fields.due_within', {
                                                            hours: step.due_hours,
                                                        })}
                                                    </span>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ol>
                                ) : (
                                    <p className="text-sm text-slate-500">{t('approvals.shape.empty')}</p>
                                )}

                                {workflow.request_type_count ? (
                                    <p className="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500">
                                        {t('approvals.admin.used_by', { count: workflow.request_type_count })}
                                    </p>
                                ) : null}
                            </CardBody>
                        </Card>
                    ))}
                </div>
            ) : (
                <Card>
                    <EmptyState
                        title={t('approvals.admin.empty')}
                        description={t('approvals.admin.empty_hint')}
                        action={<Button onClick={() => setCreating(true)}>{t('approvals.admin.add')}</Button>}
                    />
                </Card>
            )}

            {creating || editing ? (
                <WorkflowDialog
                    workflow={editing}
                    options={options}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={deleting !== null}
                message={t('approvals.admin.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(`/admin/approvals/${deleting.id}`, {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    });
                }}
            />
        </AdminLayout>
    );
}

function WorkflowDialog({
    workflow,
    options,
    onClose,
}: {
    workflow: ApprovalWorkflowAdmin | null;
    options: ApprovalAdminOptions;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        name: workflow?.name ?? '',
        name_translations: workflow?.name_translations ?? {},
        slug: workflow?.slug ?? '',
        description: workflow?.description ?? '',
        instructions: workflow?.instructions ?? '',
        is_active: workflow?.is_active ?? true,
        steps: (workflow?.steps ?? []).map(
            ({ name, mode, approver_type, approver_ids, approver_field, due_hours }): DraftStep => ({
                name,
                mode,
                approver_type,
                approver_ids,
                approver_field,
                due_hours,
            }),
        ),
    });

    const setStep = (index: number, patch: Partial<DraftStep>) => {
        form.setData(
            'steps',
            form.data.steps.map((step, i) => (i === index ? { ...step, ...patch } : step)),
        );
    };

    const submit = () => {
        workflow
            ? form.put(`/admin/approvals/${workflow.id}`, { preserveScroll: true, onSuccess: onClose })
            : form.post('/admin/approvals', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={workflow ? t('approvals.admin.edit') : t('approvals.admin.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button disabled={form.processing || form.data.name.trim() === ''} onClick={submit}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('common.labels.name')} error={form.errors.name} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <TranslationsField
                    label={t('kb.categories.name_translations')}
                    values={form.data.name_translations}
                    onChange={(values) => form.setData('name_translations', values)}
                />

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                <RichTextField
                    label={t('approvals.admin.instructions')}
                    error={form.errors.instructions}
                    help={t('approvals.admin.instructions_help')}
                    value={form.data.instructions ?? ''}
                    onChange={(html) => form.setData('instructions', html)}
                    minHeight="5rem"
                />

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <Checkbox
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                    />
                    {t('common.labels.active')}
                </label>

                <div className="border-t border-slate-200 pt-4">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium text-slate-800">{t('approvals.steps.title')}</p>
                            <p className="text-xs text-slate-500">{t('approvals.admin.steps_help')}</p>
                        </div>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => form.setData('steps', [...form.data.steps, { ...emptyStep }])}
                        >
                            {t('approvals.admin.add_step')}
                        </Button>
                    </div>

                    <div className="mt-3 space-y-3">
                        {form.data.steps.map((step, index) => (
                            <StepEditor
                                key={index}
                                index={index}
                                step={step}
                                options={options}
                                onChange={(patch) => setStep(index, patch)}
                                onRemove={() =>
                                    form.setData(
                                        'steps',
                                        form.data.steps.filter((_, i) => i !== index),
                                    )
                                }
                            />
                        ))}
                    </div>
                </div>
            </div>
        </Modal>
    );
}

function StepEditor({
    index,
    step,
    options,
    onChange,
    onRemove,
}: {
    index: number;
    step: DraftStep;
    options: ApprovalAdminOptions;
    onChange: (patch: Partial<DraftStep>) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslations();

    // Which list of ids the chosen approver type picks from. `manager` and
    // `field` pick from nothing: one reads the org chart, the other reads the
    // form.
    // Teams and roles are short lists that belong to this desk, so they stay
    // checkboxes. People are not: that pool was every agent rendered into the
    // page, and it is the one that stops working at the size where approval
    // workflows start to matter. It gets a picker instead, below.
    const pool =
        step.approver_type === 'team'
            ? options.teams
            : step.approver_type === 'role'
              ? options.roles
              : null;

    // The step holds ids; the picker shows people. Seeded from the ones the
    // saved workflow already names.
    const [approvers, setApprovers] = useState<UserSummary[]>(() =>
        options.people.filter((person) => step.approver_ids.includes(person.id)),
    );

    return (
        <div className="rounded-xl border border-slate-200 p-3">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {t('approvals.steps.number', { number: index + 1 })}
                </span>
                <button
                    type="button"
                    onClick={onRemove}
                    aria-label={t('approvals.admin.remove_step')}
                    className="rounded p-1 text-slate-500 hover:text-red-600"
                >
                    <IconX className="h-3.5 w-3.5" />
                </button>
            </div>

            <div className="mt-2 grid gap-3 sm:grid-cols-2">
                <Field label={t('approvals.fields.step_name')}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={step.name ?? ''}
                            onChange={(event) => onChange({ name: event.target.value || null })}
                        />
                    )}
                </Field>

                <Field label={t('approvals.fields.approvers')}>
                    {(props) => (
                        <Select
                            {...props}
                            value={step.approver_type}
                            onChange={(event) =>
                                // The id list belongs to the old type; keeping
                                // it would leave team ids sitting in a step
                                // that now asks for roles.
                                {
                                    setApprovers([]);
                                    onChange({
                                        approver_type: event.target.value as ApproverType,
                                        approver_ids: [],
                                    });
                                }
                            }
                        >
                            {options.approver_types.map((type) => (
                                <option key={type} value={type}>
                                    {t(`approvals.approver_type.${type}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
            </div>

            {pool ? (
                <div className="mt-3">
                    <Field label={t('approvals.fields.approvers')}>
                        {() => (
                            <CheckboxGroup
                                maxHeight="max-h-40"
                                options={pool.map((entry) => ({ value: entry.id, label: entry.name }))}
                                selected={step.approver_ids}
                                onChange={(values) => onChange({ approver_ids: values as number[] })}
                            />
                        )}
                    </Field>
                </div>
            ) : null}

            {step.approver_type === 'users' ? (
                <div className="mt-3">
                    <Field label={t('approvals.fields.approvers')}>
                        {(props) => (
                            <PersonMultiPicker
                                {...props}
                                value={approvers}
                                // Anybody with an account, not only agents: an
                                // approver is very often a budget holder who
                                // never opens the console.
                                query={{ scope: 'user' }}
                                emptyLabel={t('common.labels.none')}
                                onChange={(people) => {
                                    setApprovers(people);
                                    onChange({ approver_ids: people.map((person) => person.id) });
                                }}
                            />
                        )}
                    </Field>
                </div>
            ) : null}

            {step.approver_type === 'field' ? (
                <div className="mt-3">
                    <Field
                        label={t('approvals.fields.approver_field')}
                        help={t('approvals.fields.approver_field_help')}
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                className="font-mono text-xs"
                                value={step.approver_field ?? ''}
                                onChange={(event) => onChange({ approver_field: event.target.value || null })}
                            />
                        )}
                    </Field>
                </div>
            ) : null}

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <Field label={t('approvals.mode.any')}>
                    {(props) => (
                        <Select
                            {...props}
                            value={step.mode}
                            onChange={(event) => onChange({ mode: event.target.value as 'any' | 'all' })}
                        >
                            {options.modes.map((mode) => (
                                <option key={mode} value={mode}>
                                    {t(`approvals.mode.${mode}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('approvals.fields.due_hours')} help={t('approvals.fields.due_hours_help')}>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="number"
                            min={1}
                            value={step.due_hours ?? ''}
                            onChange={(event) =>
                                onChange({ due_hours: event.target.value ? Number(event.target.value) : null })
                            }
                        />
                    )}
                </Field>
            </div>
        </div>
    );
}
