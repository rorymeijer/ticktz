import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { IconPlus, IconX } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Checkbox,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import AdminLayout from '@/Layouts/AdminLayout';
import { PersonPicker } from '@/Components/UI/PersonPicker';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime, relativeTime } from '@/lib/datetime';
import { slugify } from '@/lib/slug';
import type { LabelSummary, PrioritySummary, StatusSummary, UserSummary } from '@/types/tickets';

/*
|--------------------------------------------------------------------------
| Shapes
|--------------------------------------------------------------------------
*/

/**
 * A condition value is whatever the field's control produces: a string from a
 * picker or a text box, a number from an age field, or a list for the "one of"
 * operators. Typed concretely rather than as `unknown` — Inertia's form state
 * needs to know it is serialisable.
 */
type ConditionValue = string | number | (string | number)[];

interface Condition {
    field: string;
    operator: string;
    value?: ConditionValue;
}

interface Action {
    type: string;
    user_id?: number | null;
    team_id?: number | null;
    priority_id?: number | null;
    queue_id?: number | null;
    status_id?: number | null;
    label_id?: number | null;
    template_id?: number | null;
    body?: string;
    internal?: boolean;
    url?: string;
    secret?: string;
}

interface Rule {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    trigger: string;
    trigger_config: { cron?: string };
    match_type: 'all' | 'any';
    conditions: Condition[];
    actions: Action[];
    is_active: boolean;
    position: number;
    stop_processing: boolean;
    run_count: number;
    match_count: number;
    last_run_at: string | null;
}

interface Execution {
    id: number;
    rule: { id: number; name: string } | null;
    ticket: { id: number; key: string } | null;
    trigger: string;
    status: 'matched' | 'skipped' | 'failed';
    reason: string | null;
    /** The condition that decided a skip, so it can be printed with names. */
    condition: Condition | null;
    actions: { type: string; ok?: boolean; detail?: string }[];
    depth: number;
    duration_ms: number;
    occurred_at: string;
}

interface NamedOption {
    id: number;
    name: string;
}

interface Options {
    triggers: string[];
    /** field name → how it is compared: id, text, set, age, flag. */
    fields: Record<string, string>;
    operators: string[];
    valueless_operators: string[];
    actions: string[];
    statuses: StatusSummary[];
    status_categories: string[];
    priorities: PrioritySummary[];
    queues: NamedOption[];
    teams: NamedOption[];
    labels: LabelSummary[];
    organizations: NamedOption[];
    request_types: NamedOption[];
    /** Active reply templates, for the `reply_template` action. */
    reply_templates: { id: number; name: string; is_internal: boolean }[];
    /** Only the people the saved rules already name — the rest are searched for. */
    people: UserSummary[];
    sources: string[];
}

/*
|--------------------------------------------------------------------------
| Which control a condition or action needs
|--------------------------------------------------------------------------
*/

/**
 * The option list a condition's value should be chosen from, or null when it
 * is free text or a number. Keyed by field so the form offers a picker rather
 * than asking an administrator to type an id.
 */
function choicesFor(field: string, options: Options): { value: string; label: string }[] | null {
    const map: Record<string, { value: string; label: string }[]> = {
        status: options.statuses.map((s) => ({ value: String(s.id), label: s.name })),
        status_category: options.status_categories.map((c) => ({ value: c, label: c })),
        priority: options.priorities.map((p) => ({ value: String(p.id), label: p.name })),
        queue: options.queues.map((q) => ({ value: String(q.id), label: q.name })),
        team: options.teams.map((t) => ({ value: String(t.id), label: t.name })),
        // `assignee` is absent on purpose: a person is not a dropdown once
        // there are four hundred of them. ConditionRow renders a picker.
        organization: options.organizations.map((o) => ({ value: String(o.id), label: o.name })),
        request_type: options.request_types.map((r) => ({ value: String(r.id), label: r.name })),
        label: options.labels.map((l) => ({ value: String(l.id), label: l.name })),
        source: options.sources.map((s) => ({ value: s, label: s })),
    };

    return map[field] ?? null;
}

/** Which extra input an action needs beyond its type. */
function actionShape(type: string): keyof Action | 'comment' | 'webhook' | null {
    switch (type) {
        case 'assign_user':
        case 'add_watcher':
            return 'user_id';
        case 'assign_team':
            return 'team_id';
        case 'set_priority':
            return 'priority_id';
        case 'set_queue':
            return 'queue_id';
        case 'transition':
            return 'status_id';
        case 'add_label':
        case 'remove_label':
            return 'label_id';
        case 'add_comment':
            return 'comment';
        case 'reply_template':
            return 'template_id';
        case 'webhook':
            return 'webhook';
        default:
            return null;
    }
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

export default function AutomationIndex({
    rules,
    executions,
    filters,
    options,
}: {
    rules: Rule[];
    executions: Execution[];
    filters: { rule: number | null; status: string | null };
    options: Options;
}) {
    const { t } = useTranslations();

    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [previewing, setPreviewing] = useState<Rule | null>(null);
    const [confirming, setConfirming] = useState<Rule | null>(null);

    const byTrigger = useMemo(() => {
        const groups = new Map<string, Rule[]>();

        for (const rule of rules) {
            groups.set(rule.trigger, [...(groups.get(rule.trigger) ?? []), rule]);
        }

        return [...groups.entries()];
    }, [rules]);

    return (
        <AdminLayout title={t('automation.title')}>
            <PageHeader
                title={t('automation.title')}
                description={t('automation.subtitle')}
                actions={
                    <Button onClick={() => setEditing('new')}>
                        <IconPlus className="h-4 w-4" />
                        {t('automation.rules.add')}
                    </Button>
                }
            />

            <div className="space-y-6">
                <section className="space-y-3">
                    <header>
                        <h2 className="text-sm font-semibold text-slate-900">{t('automation.rules.title')}</h2>
                        <p className="mt-0.5 text-sm text-slate-500">{t('automation.rules.description')}</p>
                    </header>

                    {rules.length === 0 ? (
                        <EmptyState title={t('automation.rules.empty')} />
                    ) : (
                        byTrigger.map(([trigger, group]) => (
                            <div key={trigger} className="space-y-2">
                                <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {t(`automation.triggers.${trigger}`)}
                                </h3>
                                {group.map((rule) => (
                                    <RuleCard
                                        key={rule.id}
                                        rule={rule}
                                        options={options}
                                        onEdit={() => setEditing(rule)}
                                        onPreview={() => setPreviewing(rule)}
                                        onDelete={() => setConfirming(rule)}
                                    />
                                ))}
                            </div>
                        ))
                    )}
                </section>

                <ExecutionLog executions={executions} rules={rules} filters={filters} options={options} />
            </div>

            {editing ? (
                <RuleDialog
                    rule={editing === 'new' ? null : editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            {previewing ? <PreviewDialog rule={previewing} onClose={() => setPreviewing(null)} /> : null}

            <ConfirmDialog
                open={confirming !== null}
                message={t('automation.rules.confirm_delete')}
                onCancel={() => setConfirming(null)}
                onConfirm={() => {
                    if (confirming) {
                        router.delete(`/admin/automation/rules/${confirming.id}`, { preserveScroll: true });
                    }
                    setConfirming(null);
                }}
            />
        </AdminLayout>
    );
}

/*
|--------------------------------------------------------------------------
| A rule, read as a sentence
|--------------------------------------------------------------------------
*/

function RuleCard({
    rule,
    options,
    onEdit,
    onPreview,
    onDelete,
}: {
    rule: Rule;
    options: Options;
    onEdit: () => void;
    onPreview: () => void;
    onDelete: () => void;
}) {
    const { t, locale } = useTranslations();

    return (
        <Card className={rule.is_active ? undefined : 'opacity-60'}>
            <CardHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {rule.name}
                        {rule.is_active ? null : <Badge tone="slate">{t('automation.rules.inactive')}</Badge>}
                        {rule.stop_processing ? <Badge tone="amber">{t('automation.rules.stops')}</Badge> : null}
                    </span>
                }
                description={rule.description}
                actions={
                    <div className="flex flex-wrap items-center gap-1">
                        <Button variant="ghost" size="sm" onClick={onPreview}>
                            {t('automation.preview.title')}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.patch(`/admin/automation/rules/${rule.id}/active`, {}, { preserveScroll: true })
                            }
                        >
                            {rule.is_active ? t('automation.rules.disable') : t('automation.rules.enable')}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            {t('common.actions.edit')}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onDelete}>
                            {t('common.actions.delete')}
                        </Button>
                    </div>
                }
            />
            <CardBody className="space-y-3">
                <dl className="space-y-1.5 text-sm">
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="w-12 shrink-0 text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t('automation.rules.conditions')}
                        </dt>
                        <dd className="text-slate-700">
                            {rule.conditions.length === 0 ? (
                                <span className="text-slate-500">{t('automation.rules.conditions_any')}</span>
                            ) : (
                                <span>
                                    {rule.conditions.map((condition, index) => (
                                        <span key={index}>
                                            {index > 0 ? (
                                                <span className="text-slate-500">
                                                    {' '}
                                                    {rule.match_type === 'all' ? '·' : '/'}{' '}
                                                </span>
                                            ) : null}
                                            {describeCondition(condition, options, t)}
                                        </span>
                                    ))}
                                </span>
                            )}
                        </dd>
                    </div>
                    <div className="flex flex-wrap gap-x-2">
                        <dt className="w-12 shrink-0 text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t('automation.rules.actions')}
                        </dt>
                        <dd className="text-slate-700">
                            {rule.actions.map((action, index) => (
                                <span key={index}>
                                    {index > 0 ? <span className="text-slate-500"> · </span> : null}
                                    {describeAction(action, options, t)}
                                </span>
                            ))}
                        </dd>
                    </div>
                </dl>

                <p className="border-t border-slate-100 pt-2 text-xs text-slate-500">
                    {rule.run_count === 0
                        ? t('automation.rules.never_run')
                        : `${t('automation.rules.runs', { matched: rule.match_count, runs: rule.run_count })} · ${t(
                              'automation.rules.last_run',
                              { time: relativeTime(rule.last_run_at, t) },
                          )}`}
                    {rule.trigger === 'scheduled' ? (
                        <span className="ml-2 font-mono">{rule.trigger_config.cron || '0 * * * *'}</span>
                    ) : null}
                </p>
            </CardBody>
        </Card>
    );
}

type Translator = (key: string, replacements?: Record<string, string | number>) => string;

function describeCondition(condition: Condition, options: Options, t: Translator): string {
    const field = t(`automation.fields.${condition.field}`);
    const operator = t(`automation.operators.${condition.operator}`);

    if (options.valueless_operators.includes(condition.operator)) {
        return `${field} ${operator}`;
    }

    const choices = choicesFor(condition.field, options);
    const raw = Array.isArray(condition.value) ? condition.value : [condition.value];

    const printed = raw
        .map((value) => choices?.find((choice) => choice.value === String(value))?.label ?? String(value ?? ''))
        .join(', ');

    return `${field} ${operator} ${printed}`;
}

function describeAction(action: Action, options: Options, t: Translator): string {
    const label = t(`automation.actions.${action.type}`);

    const target =
        options.people.find((person) => person.id === action.user_id)?.name ??
        options.teams.find((team) => team.id === action.team_id)?.name ??
        options.priorities.find((p) => p.id === action.priority_id)?.name ??
        options.queues.find((q) => q.id === action.queue_id)?.name ??
        options.statuses.find((s) => s.id === action.status_id)?.name ??
        options.labels.find((l) => l.id === action.label_id)?.name ??
        action.url ??
        null;

    return target ? `${label} ${target}` : label;
}

/*
|--------------------------------------------------------------------------
| The log
|--------------------------------------------------------------------------
*/

/**
 * What one row of the log says it did, or did not do.
 *
 * A skip prints the condition that decided it, rendered the same way the rule
 * itself is — "Priority is Urgent", not "priority is 1". The stored sentence
 * is the fallback for the cases with no single condition to blame.
 */
function detailOf(execution: Execution, options: Options, t: Translator): string {
    if (execution.condition) {
        return describeCondition(execution.condition, options, t);
    }

    if (execution.reason) {
        return execution.reason;
    }

    if (execution.actions.length === 0) {
        return t('automation.log.no_action');
    }

    return execution.actions
        .map((action) => {
            const label = t(`automation.actions.${action.type}`);

            return action.detail ? `${label}: ${action.detail}` : label;
        })
        .join(' · ');
}

function ExecutionLog({
    executions,
    rules,
    filters,
    options,
}: {
    executions: Execution[];
    rules: Rule[];
    filters: { rule: number | null; status: string | null };
    options: Options;
}) {
    const { t, locale } = useTranslations();

    const filter = (key: string, value: string) => {
        const next = { ...filters, [key]: value || null };

        router.get('/admin/automation', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const tone = { matched: 'green', skipped: 'slate', failed: 'red' } as const;

    return (
        <section className="space-y-3">
            <header className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold text-slate-900">{t('automation.log.title')}</h2>
                    <p className="mt-0.5 text-sm text-slate-500">{t('automation.log.description')}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Select
                        value={filters.rule ?? ''}
                        onChange={(event) => filter('rule', event.target.value)}
                        aria-label={t('automation.log.columns.rule')}
                        className="w-auto"
                    >
                        <option value="">{t('automation.log.all_rules')}</option>
                        {rules.map((rule) => (
                            <option key={rule.id} value={rule.id}>
                                {rule.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        value={filters.status ?? ''}
                        onChange={(event) => filter('status', event.target.value)}
                        aria-label={t('automation.log.columns.result')}
                        className="w-auto"
                    >
                        <option value="">{t('automation.log.all_results')}</option>
                        {(['matched', 'skipped', 'failed'] as const).map((status) => (
                            <option key={status} value={status}>
                                {t(`automation.log.status.${status}`)}
                            </option>
                        ))}
                    </Select>
                </div>
            </header>

            <Card>
                {executions.length === 0 ? (
                    <EmptyState title={t('automation.log.empty')} />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('automation.log.columns.when')}</TH>
                                <TH>{t('automation.log.columns.rule')}</TH>
                                <TH>{t('automation.log.columns.ticket')}</TH>
                                <TH>{t('automation.log.columns.result')}</TH>
                                <TH>{t('automation.log.columns.detail')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {executions.map((execution) => (
                                <TR key={execution.id}>
                                    <TD>
                                        <time
                                            className="whitespace-nowrap text-xs text-slate-500"
                                            dateTime={execution.occurred_at}
                                            title={formatDateTime(execution.occurred_at, locale)}
                                        >
                                            {relativeTime(execution.occurred_at, t)}
                                        </time>
                                    </TD>
                                    <TD>
                                        <span className="text-sm">{execution.rule?.name ?? '—'}</span>
                                        {execution.depth > 0 ? (
                                            <span
                                                className="ml-1 text-xs text-amber-600"
                                                title={t('automation.log.depth', { depth: execution.depth })}
                                            >
                                                ↳{execution.depth}
                                            </span>
                                        ) : null}
                                    </TD>
                                    <TD>
                                        {execution.ticket ? (
                                            <a
                                                href={`/agent/tickets/${execution.ticket.key}`}
                                                className="font-mono text-xs text-brand-600 hover:text-brand-700"
                                            >
                                                {execution.ticket.key}
                                            </a>
                                        ) : (
                                            '—'
                                        )}
                                    </TD>
                                    <TD>
                                        <Badge tone={tone[execution.status]}>
                                            {t(`automation.log.status.${execution.status}`)}
                                        </Badge>
                                    </TD>
                                    <TD>
                                        <span className="text-xs text-slate-600">
                                            {detailOf(execution, options, t)}
                                        </span>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>
        </section>
    );
}

/*
|--------------------------------------------------------------------------
| The builder
|--------------------------------------------------------------------------
*/

function RuleDialog({ rule, options, onClose }: { rule: Rule | null; options: Options; onClose: () => void }) {
    const { t } = useTranslations();

    const form = useForm({
        name: rule?.name ?? '',
        slug: rule?.slug ?? '',
        description: rule?.description ?? '',
        trigger: rule?.trigger ?? options.triggers[0],
        trigger_config: { cron: rule?.trigger_config?.cron ?? '' },
        match_type: rule?.match_type ?? ('all' as 'all' | 'any'),
        conditions: rule?.conditions ?? ([] as Condition[]),
        actions: rule?.actions ?? ([{ type: 'assign_team' }] as Action[]),
        is_active: rule?.is_active ?? true,
        position: rule?.position ?? 100,
        stop_processing: rule?.stop_processing ?? false,
    });

    const setCondition = (index: number, next: Condition) => {
        form.setData(
            'conditions',
            form.data.conditions.map((condition, i) => (i === index ? next : condition)),
        );
    };

    const setAction = (index: number, next: Action) => {
        form.setData(
            'actions',
            form.data.actions.map((action, i) => (i === index ? next : action)),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const done = { preserveScroll: true, onSuccess: onClose };

        if (rule) {
            form.put(`/admin/automation/rules/${rule.id}`, done);
        } else {
            form.post('/admin/automation/rules', done);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={rule ? t('automation.rules.edit') : t('automation.rules.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing || form.data.actions.length === 0}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('common.labels.name')} error={form.errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                onChange={(event) => {
                                    form.setData('name', event.target.value);

                                    if (!rule) {
                                        form.setData('slug', slugify(event.target.value));
                                    }
                                }}
                            />
                        )}
                    </Field>

                    <Field label={t('automation.fields.slug')} error={form.errors.slug} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.slug}
                                onChange={(event) => form.setData('slug', slugify(event.target.value))}
                            />
                        )}
                    </Field>
                </div>

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                {/* ------------- When ------------- */}
                <fieldset className="space-y-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('automation.rules.trigger')}</legend>

                    <Field
                        label={t('automation.rules.trigger')}
                        help={t(`automation.trigger_help.${form.data.trigger}`, {}) || undefined}
                        error={form.errors.trigger}
                    >
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.trigger}
                                onChange={(event) => form.setData('trigger', event.target.value)}
                            >
                                {options.triggers.map((trigger) => (
                                    <option key={trigger} value={trigger}>
                                        {t(`automation.triggers.${trigger}`)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    {form.data.trigger === 'scheduled' ? (
                        <Field
                            label={t('automation.rules.schedule')}
                            help={t('automation.rules.schedule_help')}
                            error={form.errors['trigger_config.cron']}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.trigger_config.cron}
                                    onChange={(event) => form.setData('trigger_config', { cron: event.target.value })}
                                    placeholder="0 * * * *"
                                    className="font-mono"
                                />
                            )}
                        </Field>
                    ) : null}
                </fieldset>

                {/* ------------- And ------------- */}
                <fieldset className="space-y-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('automation.rules.conditions')}</legend>

                    <Field label={t('automation.rules.match_label')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.match_type}
                                onChange={(event) => form.setData('match_type', event.target.value as 'all' | 'any')}
                                className="w-auto"
                            >
                                <option value="all">{t('automation.rules.match_all')}</option>
                                <option value="any">{t('automation.rules.match_any')}</option>
                            </Select>
                        )}
                    </Field>

                    {form.data.conditions.length === 0 ? (
                        <p className="text-xs text-slate-500">{t('automation.conditions.empty')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {form.data.conditions.map((condition, index) => (
                                <li key={index}>
                                    <ConditionRow
                                        condition={condition}
                                        options={options}
                                        onChange={(next) => setCondition(index, next)}
                                        onRemove={() =>
                                            form.setData(
                                                'conditions',
                                                form.data.conditions.filter((_, i) => i !== index),
                                            )
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() =>
                            form.setData('conditions', [
                                ...form.data.conditions,
                                { field: Object.keys(options.fields)[0], operator: 'is', value: '' },
                            ])
                        }
                    >
                        <IconPlus className="h-3.5 w-3.5" />
                        {t('automation.conditions.add')}
                    </Button>
                </fieldset>

                {/* ------------- Then ------------- */}
                <fieldset className="space-y-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('automation.rules.actions')}</legend>

                    {form.data.actions.length === 0 ? (
                        <p className="text-xs text-amber-700">{t('automation.rules.needs_action')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {form.data.actions.map((action, index) => (
                                <li key={index}>
                                    <ActionRow
                                        action={action}
                                        options={options}
                                        onChange={(next) => setAction(index, next)}
                                        onRemove={() =>
                                            form.setData(
                                                'actions',
                                                form.data.actions.filter((_, i) => i !== index),
                                            )
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() => form.setData('actions', [...form.data.actions, { type: 'assign_team' }])}
                    >
                        <IconPlus className="h-3.5 w-3.5" />
                        {t('automation.action_config.add')}
                    </Button>
                </fieldset>

                {/* ------------- Housekeeping ------------- */}
                <fieldset className="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                    <Field
                        label={t('automation.rules.position')}
                        help={t('automation.rules.position_help')}
                        error={form.errors.position}
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                type="number"
                                min={0}
                                value={form.data.position}
                                onChange={(event) => form.setData('position', Number(event.target.value))}
                            />
                        )}
                    </Field>

                    <div className="space-y-2 sm:pt-6">
                        <Toggle
                            checked={form.data.is_active}
                            onChange={(value) => form.setData('is_active', value)}
                            label={t('automation.rules.active')}
                        />
                        <Toggle
                            checked={form.data.stop_processing}
                            onChange={(value) => form.setData('stop_processing', value)}
                            label={t('automation.rules.stop_processing')}
                        />
                        <p className="text-xs text-slate-500">{t('automation.rules.stop_processing_help')}</p>
                    </div>
                </fieldset>
            </form>
        </Modal>
    );
}

function ConditionRow({
    condition,
    options,
    onChange,
    onRemove,
}: {
    condition: Condition;
    options: Options;
    onChange: (condition: Condition) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslations();
    const choices = choicesFor(condition.field, options);
    const needsValue = !options.valueless_operators.includes(condition.operator);
    const isNumeric = options.fields[condition.field] === 'age';
    const picksPerson = condition.field === 'assignee';

    // The condition stores an id; the picker shows a person. Seeded from the
    // people the saved rules already name.
    const [person, setPerson] = useState<UserSummary | null>(
        () => options.people.find((candidate) => String(candidate.id) === String(condition.value)) ?? null,
    );

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-md border border-slate-200 p-2">
            <Select
                value={condition.field}
                onChange={(event) => {
                    setPerson(null);
                    onChange({ ...condition, field: event.target.value, value: '' });
                }}
                aria-label={t('automation.conditions.field')}
                className="w-auto"
            >
                {Object.keys(options.fields).map((field) => (
                    <option key={field} value={field}>
                        {t(`automation.fields.${field}`)}
                    </option>
                ))}
            </Select>

            <Select
                value={condition.operator}
                onChange={(event) => onChange({ ...condition, operator: event.target.value })}
                aria-label={t('automation.conditions.operator')}
                className="w-auto"
            >
                {options.operators.map((operator) => (
                    <option key={operator} value={operator}>
                        {t(`automation.operators.${operator}`)}
                    </option>
                ))}
            </Select>

            {needsValue ? (
                picksPerson ? (
                    <div className="w-64">
                        <PersonPicker
                            value={person}
                            allowNobody
                            query={{ scope: 'agent' }}
                            placeholder={t('automation.conditions.value')}
                            onChange={(chosen) => {
                                setPerson(chosen);
                                onChange({ ...condition, value: chosen ? String(chosen.id) : '' });
                            }}
                        />
                    </div>
                ) : choices ? (
                    <Select
                        value={String(condition.value ?? '')}
                        onChange={(event) => onChange({ ...condition, value: event.target.value })}
                        aria-label={t('automation.conditions.value')}
                        className="w-auto"
                    >
                        <option value="">—</option>
                        {choices.map((choice) => (
                            <option key={choice.value} value={choice.value}>
                                {choice.label}
                            </option>
                        ))}
                    </Select>
                ) : (
                    <TextInput
                        type={isNumeric ? 'number' : 'text'}
                        value={String(condition.value ?? '')}
                        onChange={(event) => onChange({ ...condition, value: event.target.value })}
                        aria-label={t('automation.conditions.value')}
                        className="w-44"
                    />
                )
            ) : null}

            <button
                type="button"
                onClick={onRemove}
                className="ml-auto rounded p-1 text-slate-600 hover:bg-slate-100 hover:text-red-600"
                aria-label={t('common.actions.remove')}
            >
                <IconX className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

function ActionRow({
    action,
    options,
    onChange,
    onRemove,
}: {
    action: Action;
    options: Options;
    onChange: (action: Action) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslations();
    const shape = actionShape(action.type);

    // `user_id` is deliberately not in here. Everything else in this map is a
    // list that belongs to the desk and fits in a dropdown — statuses,
    // priorities, queues, teams. People are not, and rendering every agent
    // into this page was the thing that stopped working at four hundred of
    // them. It gets a picker below.
    const pickers: Record<string, { key: keyof Action; items: { id: number; name: string }[]; label: string }> = {
        team_id: { key: 'team_id', items: options.teams, label: t('automation.action_config.team') },
        priority_id: { key: 'priority_id', items: options.priorities, label: t('automation.action_config.priority') },
        queue_id: { key: 'queue_id', items: options.queues, label: t('automation.action_config.queue') },
        status_id: { key: 'status_id', items: options.statuses, label: t('automation.action_config.status') },
        label_id: { key: 'label_id', items: options.labels, label: t('automation.action_config.label') },
        template_id: {
            key: 'template_id',
            items: options.reply_templates,
            label: t('automation.action_config.template'),
        },
    };

    const picker = shape && shape in pickers ? pickers[shape as string] : null;

    // The rule stores an id; the picker shows a person. Seeded from the people
    // the saved rules already name.
    const [person, setPerson] = useState<UserSummary | null>(
        () => options.people.find((candidate) => candidate.id === action.user_id) ?? null,
    );

    return (
        <div className="space-y-2 rounded-md border border-slate-200 p-2">
            <div className="flex flex-wrap items-center gap-2">
                <Select
                    value={action.type}
                    onChange={(event) => onChange({ type: event.target.value })}
                    aria-label={t('automation.rules.actions')}
                    className="w-auto"
                >
                    {options.actions.map((type) => (
                        <option key={type} value={type}>
                            {t(`automation.actions.${type}`)}
                        </option>
                    ))}
                </Select>

                {picker ? (
                    <Select
                        value={String(action[picker.key] ?? '')}
                        onChange={(event) =>
                            onChange({ ...action, [picker.key]: Number(event.target.value) || null })
                        }
                        aria-label={picker.label}
                        className="w-auto"
                    >
                        <option value="">—</option>
                        {picker.items.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.name}
                            </option>
                        ))}
                    </Select>
                ) : null}

                {shape === 'user_id' ? (
                    <div className="w-64">
                        <PersonPicker
                            value={person}
                            allowNobody
                            query={{ scope: 'agent' }}
                            placeholder={t('automation.action_config.user')}
                            onChange={(chosen) => {
                                setPerson(chosen);
                                onChange({ ...action, user_id: chosen?.id ?? null });
                            }}
                        />
                    </div>
                ) : null}

                <button
                    type="button"
                    onClick={onRemove}
                    className="ml-auto rounded p-1 text-slate-600 hover:bg-slate-100 hover:text-red-600"
                    aria-label={t('common.actions.remove')}
                >
                    <IconX className="h-3.5 w-3.5" />
                </button>
            </div>

            {shape === 'template_id' ? (
                <p className="text-xs text-slate-500">
                    {options.reply_templates.length === 0
                        ? t('automation.action_config.template_none')
                        : t('automation.action_config.template_help')}
                </p>
            ) : null}

            {shape === 'comment' ? (
                <div className="space-y-2">
                    <Textarea
                        rows={3}
                        value={action.body ?? ''}
                        onChange={(event) => onChange({ ...action, body: event.target.value })}
                        aria-label={t('automation.action_config.body')}
                    />
                    <p className="text-xs text-slate-500">{t('automation.action_config.body_help')}</p>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <Checkbox
                            checked={action.internal ?? true}
                            onChange={(event) => onChange({ ...action, internal: event.target.checked })}
                        />
                        {t('automation.action_config.internal')}
                    </label>
                    <p className="text-xs text-slate-500">{t('automation.action_config.internal_help')}</p>
                </div>
            ) : null}

            {shape === 'webhook' ? (
                <div className="grid gap-2 sm:grid-cols-2">
                    <div>
                        <TextInput
                            value={action.url ?? ''}
                            onChange={(event) => onChange({ ...action, url: event.target.value })}
                            placeholder="https://"
                            aria-label={t('automation.action_config.url')}
                        />
                        <p className="mt-1 text-xs text-slate-500">{t('automation.action_config.url_help')}</p>
                    </div>
                    <div>
                        <TextInput
                            type="password"
                            value={action.secret ?? ''}
                            onChange={(event) => onChange({ ...action, secret: event.target.value })}
                            aria-label={t('automation.action_config.secret')}
                        />
                        <p className="mt-1 text-xs text-slate-500">{t('automation.action_config.secret_help')}</p>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function PreviewDialog({ rule, onClose }: { rule: Rule; onClose: () => void }) {
    const { t } = useTranslations();
    const form = useForm({ ticket_key: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(`/admin/automation/rules/${rule.id}/preview`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('automation.preview.title')}
            description={rule.name}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {t('automation.preview.run')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-3">
                <p className="text-sm text-slate-500">{t('automation.preview.description')}</p>

                <Field label={t('automation.preview.ticket')} error={form.errors.ticket_key} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.ticket_key}
                            onChange={(event) => form.setData('ticket_key', event.target.value)}
                            placeholder="SUP-1"
                            className="font-mono"
                        />
                    )}
                </Field>
            </form>
        </Modal>
    );
}
