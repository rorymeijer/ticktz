import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { IconPlus, IconX } from '@/Components/Icons';
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
    Toggle,
} from '@/Components/UI';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';
import type { PrioritySummary } from '@/types/tickets';

/*
|--------------------------------------------------------------------------
| Shapes
|--------------------------------------------------------------------------
*/

type Metric = 'first_response' | 'resolution';

type ActionType = 'notify' | 'raise_priority' | 'assign_team';

interface EscalationAction {
    type: ActionType;
    to?: string;
    team_id?: number;
}

interface Escalation {
    at: number;
    actions: EscalationAction[];
}

interface Goal {
    id: number;
    sla_policy_id: number;
    metric: Metric;
    priority_id: number | null;
    request_type_id: number | null;
    target_minutes: number;
    escalations: Escalation[];
    is_active: boolean;
}

interface Conditions {
    queue_ids: number[];
    team_ids: number[];
    request_type_ids: number[];
    organization_ids: number[];
    priority_ids: number[];
}

interface Policy {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    business_calendar_id: number;
    calendar_name: string | null;
    is_active: boolean;
    is_default: boolean;
    position: number;
    conditions: Conditions;
    goals: Goal[];
}

interface Holiday {
    id: number;
    name: string;
    date: string;
    is_recurring: boolean;
}

type WorkingHours = Record<string, string[][]>;

interface Calendar {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    timezone: string;
    working_hours: WorkingHours;
    is_default: boolean;
    policies_count: number;
    holidays: Holiday[];
}

interface NamedOption {
    id: number;
    name: string;
}

interface Options {
    metrics: Metric[];
    actions: ActionType[];
    notify_targets: string[];
    days: string[];
    timezones: string[];
    priorities: PrioritySummary[];
    queues: NamedOption[];
    teams: NamedOption[];
    request_types: NamedOption[];
    organizations: NamedOption[];
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const CONDITION_KEYS: { key: keyof Conditions; options: keyof Options }[] = [
    { key: 'queue_ids', options: 'queues' },
    { key: 'team_ids', options: 'teams' },
    { key: 'request_type_ids', options: 'request_types' },
    { key: 'organization_ids', options: 'organizations' },
    { key: 'priority_ids', options: 'priorities' },
];

const emptyConditions = (): Conditions => ({
    queue_ids: [],
    team_ids: [],
    request_type_ids: [],
    organization_ids: [],
    priority_ids: [],
});

/**
 * Targets are stored in minutes but nobody thinks in minutes past an hour or
 * two. The form offers the unit that divides cleanly, so a 480-minute target
 * reads as "8 hours" rather than as a number to divide in your head.
 */
function splitDuration(minutes: number): { value: number; unit: 'minutes' | 'hours' | 'days' } {
    if (minutes % (60 * 8) === 0 && minutes >= 60 * 8) {
        return { value: minutes / (60 * 8), unit: 'days' };
    }

    if (minutes % 60 === 0 && minutes >= 60) {
        return { value: minutes / 60, unit: 'hours' };
    }

    return { value: minutes, unit: 'minutes' };
}

function toMinutes(value: number, unit: 'minutes' | 'hours' | 'days'): number {
    if (unit === 'days') return value * 60 * 8;
    if (unit === 'hours') return value * 60;

    return value;
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

export default function SlaIndex({
    policies,
    calendars,
    options,
}: {
    policies: Policy[];
    calendars: Calendar[];
    options: Options;
}) {
    const { t } = useTranslations();

    const [policyDialog, setPolicyDialog] = useState<Policy | 'new' | null>(null);
    const [goalDialog, setGoalDialog] = useState<{ policy: Policy; goal: Goal | null } | null>(null);
    const [calendarDialog, setCalendarDialog] = useState<Calendar | 'new' | null>(null);
    const [holidayDialog, setHolidayDialog] = useState<Calendar | null>(null);
    const [confirming, setConfirming] = useState<{ url: string; message: string } | null>(null);

    return (
        <AdminLayout title={t('sla.title')}>
            <PageHeader
                title={t('sla.title')}
                description={t('sla.subtitle')}
                actions={
                    <Button onClick={() => setPolicyDialog('new')}>
                        <IconPlus className="h-4 w-4" />
                        {t('sla.policies.add')}
                    </Button>
                }
            />

            <div className="space-y-6">
                {/* ---------------- Policies ---------------- */}
                <section className="space-y-3">
                    <header>
                        <h2 className="text-sm font-semibold text-slate-900">{t('sla.policies.title')}</h2>
                        <p className="mt-0.5 text-sm text-slate-500">{t('sla.policies.description')}</p>
                    </header>

                    {policies.length === 0 ? (
                        <EmptyState title={t('sla.policies.empty')} />
                    ) : (
                        policies.map((policy) => (
                            <PolicyCard
                                key={policy.id}
                                policy={policy}
                                options={options}
                                onEdit={() => setPolicyDialog(policy)}
                                onDelete={() =>
                                    setConfirming({
                                        url: `/admin/sla/policies/${policy.id}`,
                                        message: t('sla.policies.confirm_delete'),
                                    })
                                }
                                onAddGoal={() => setGoalDialog({ policy, goal: null })}
                                onEditGoal={(goal) => setGoalDialog({ policy, goal })}
                                onDeleteGoal={(goal) =>
                                    setConfirming({
                                        url: `/admin/sla/goals/${goal.id}`,
                                        message: t('sla.goals.confirm_delete'),
                                    })
                                }
                            />
                        ))
                    )}
                </section>

                {/* ---------------- Calendars ---------------- */}
                <section className="space-y-3">
                    <header className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 className="text-sm font-semibold text-slate-900">{t('sla.calendars.title')}</h2>
                            <p className="mt-0.5 text-sm text-slate-500">{t('sla.calendars.description')}</p>
                        </div>
                        <Button variant="secondary" onClick={() => setCalendarDialog('new')}>
                            <IconPlus className="h-4 w-4" />
                            {t('sla.calendars.add')}
                        </Button>
                    </header>

                    <div className="grid gap-3 lg:grid-cols-2">
                        {calendars.map((calendar) => (
                            <CalendarCard
                                key={calendar.id}
                                calendar={calendar}
                                options={options}
                                onEdit={() => setCalendarDialog(calendar)}
                                onAddHoliday={() => setHolidayDialog(calendar)}
                                onDelete={() =>
                                    setConfirming({
                                        url: `/admin/sla/calendars/${calendar.id}`,
                                        message: t('sla.calendars.confirm_delete'),
                                    })
                                }
                                onDeleteHoliday={(holiday) =>
                                    setConfirming({
                                        url: `/admin/sla/holidays/${holiday.id}`,
                                        message: t('sla.holidays.confirm_delete'),
                                    })
                                }
                            />
                        ))}
                    </div>
                </section>
            </div>

            {policyDialog ? (
                <PolicyDialog
                    policy={policyDialog === 'new' ? null : policyDialog}
                    calendars={calendars}
                    options={options}
                    onClose={() => setPolicyDialog(null)}
                />
            ) : null}

            {goalDialog ? (
                <GoalDialog
                    policy={goalDialog.policy}
                    goal={goalDialog.goal}
                    options={options}
                    onClose={() => setGoalDialog(null)}
                />
            ) : null}

            {calendarDialog ? (
                <CalendarDialog
                    calendar={calendarDialog === 'new' ? null : calendarDialog}
                    options={options}
                    onClose={() => setCalendarDialog(null)}
                />
            ) : null}

            {holidayDialog ? (
                <HolidayDialog calendar={holidayDialog} onClose={() => setHolidayDialog(null)} />
            ) : null}

            <ConfirmDialog
                open={confirming !== null}
                message={confirming?.message ?? ''}
                onCancel={() => setConfirming(null)}
                onConfirm={() => {
                    if (confirming) {
                        router.delete(confirming.url, { preserveScroll: true });
                    }
                    setConfirming(null);
                }}
            />
        </AdminLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Policy card
|--------------------------------------------------------------------------
*/

function PolicyCard({
    policy,
    options,
    onEdit,
    onDelete,
    onAddGoal,
    onEditGoal,
    onDeleteGoal,
}: {
    policy: Policy;
    options: Options;
    onEdit: () => void;
    onDelete: () => void;
    onAddGoal: () => void;
    onEditGoal: (goal: Goal) => void;
    onDeleteGoal: (goal: Goal) => void;
}) {
    const { t } = useTranslations();

    const conditionSummary = CONDITION_KEYS.flatMap(({ key, options: optionKey }) => {
        const ids = policy.conditions[key] ?? [];

        if (ids.length === 0) return [];

        const pool = options[optionKey] as NamedOption[];
        const names = ids.map((id) => pool.find((option) => option.id === id)?.name).filter(Boolean);

        return names.length > 0 ? [names.join(', ')] : [];
    });

    return (
        <Card>
            <CardHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {policy.name}
                        {policy.is_default ? <Badge tone="brand">{t('sla.policies.default')}</Badge> : null}
                        {policy.is_active ? null : <Badge tone="slate">{t('sla.policies.inactive')}</Badge>}
                    </span>
                }
                description={policy.description}
                actions={
                    <div className="flex flex-wrap items-center gap-1">
                        <Button variant="ghost" size="sm" onClick={onAddGoal}>
                            {t('sla.goals.add')}
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
                <dl className="flex flex-wrap gap-x-6 gap-y-1 text-xs">
                    <div className="flex gap-1.5">
                        <dt className="text-slate-500">{t('sla.panel.calendar')}</dt>
                        <dd className="text-slate-700">{policy.calendar_name ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1.5">
                        <dt className="text-slate-500">{t('sla.policies.position')}</dt>
                        <dd className="text-slate-700">{policy.position}</dd>
                    </div>
                    <div className="flex gap-1.5">
                        <dt className="text-slate-500">{t('sla.policies.conditions')}</dt>
                        <dd className="text-slate-700">
                            {conditionSummary.length > 0
                                ? conditionSummary.join(' · ')
                                : t('sla.policies.conditions_any')}
                        </dd>
                    </div>
                </dl>

                {policy.goals.length === 0 ? (
                    <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">{t('sla.goals.empty')}</p>
                ) : (
                    <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
                        {policy.goals.map((goal) => (
                            <GoalRow
                                key={goal.id}
                                goal={goal}
                                options={options}
                                onEdit={() => onEditGoal(goal)}
                                onDelete={() => onDeleteGoal(goal)}
                            />
                        ))}
                    </ul>
                )}
            </CardBody>
        </Card>
    );
}

function GoalRow({
    goal,
    options,
    onEdit,
    onDelete,
}: {
    goal: Goal;
    options: Options;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const { t } = useTranslations();
    const { value, unit } = splitDuration(goal.target_minutes);

    const scope = [
        goal.priority_id
            ? options.priorities.find((priority) => priority.id === goal.priority_id)?.name
            : t('sla.goals.any_priority'),
        goal.request_type_id
            ? options.request_types.find((type) => type.id === goal.request_type_id)?.name
            : t('sla.goals.any_request_type'),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
            <div className="min-w-0">
                <p className="text-sm font-medium text-slate-800">
                    {t(`sla.metrics.${goal.metric}`)}
                    <span className="ml-2 font-normal text-slate-500">
                        {value} {t(`sla.goals.unit_${unit}`, { count: value })}
                    </span>
                </p>
                <p className="truncate text-xs text-slate-500">{scope}</p>
                {goal.escalations.length > 0 ? (
                    <p className="mt-0.5 flex flex-wrap gap-1">
                        {goal.escalations.map((escalation) => (
                            <Badge key={escalation.at} tone={escalation.at >= 100 ? 'red' : 'amber'}>
                                {escalation.at}%
                            </Badge>
                        ))}
                    </p>
                ) : null}
            </div>
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" onClick={onEdit}>
                    {t('common.actions.edit')}
                </Button>
                <Button variant="ghost" size="sm" onClick={onDelete}>
                    {t('common.actions.delete')}
                </Button>
            </div>
        </li>
    );
}

/*
|--------------------------------------------------------------------------
| Calendar card
|--------------------------------------------------------------------------
*/

function CalendarCard({
    calendar,
    options,
    onEdit,
    onDelete,
    onAddHoliday,
    onDeleteHoliday,
}: {
    calendar: Calendar;
    options: Options;
    onEdit: () => void;
    onDelete: () => void;
    onAddHoliday: () => void;
    onDeleteHoliday: (holiday: Holiday) => void;
}) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {calendar.name}
                        {calendar.is_default ? <Badge tone="brand">{t('sla.calendars.default')}</Badge> : null}
                    </span>
                }
                description={calendar.timezone}
                actions={
                    <div className="flex items-center gap-1">
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            {t('common.actions.edit')}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={onDelete} disabled={calendar.policies_count > 0}>
                            {t('common.actions.delete')}
                        </Button>
                    </div>
                }
            />
            <CardBody className="space-y-3">
                <ul className="space-y-0.5 text-xs">
                    {options.days.map((day) => {
                        const blocks = calendar.working_hours[day] ?? [];

                        return (
                            <li key={day} className="flex gap-2">
                                <span className="w-10 shrink-0 text-slate-500">{t(`sla.days_short.${day}`)}</span>
                                <span className={blocks.length === 0 ? 'text-slate-500' : 'text-slate-700'}>
                                    {blocks.length === 0
                                        ? t('sla.calendars.closed')
                                        : blocks.map((block) => `${block[0]}–${block[1]}`).join(', ')}
                                </span>
                            </li>
                        );
                    })}
                </ul>

                <div className="border-t border-slate-100 pt-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-xs font-semibold text-slate-700">{t('sla.holidays.title')}</h3>
                        <Button variant="ghost" size="sm" onClick={onAddHoliday}>
                            {t('sla.holidays.add')}
                        </Button>
                    </div>

                    {calendar.holidays.length === 0 ? (
                        <p className="mt-1 text-xs text-slate-500">{t('sla.holidays.empty')}</p>
                    ) : (
                        <ul className="mt-1.5 flex flex-wrap gap-1.5">
                            {calendar.holidays.map((holiday) => (
                                <li key={holiday.id}>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 py-0.5 pl-2 pr-1 text-xs text-slate-700">
                                        <span>
                                            {holiday.name}
                                            <span className="ml-1 text-slate-500">
                                                {holiday.is_recurring
                                                    ? holiday.date.slice(5)
                                                    : holiday.date}
                                            </span>
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => onDeleteHoliday(holiday)}
                                            className="rounded-full p-0.5 text-slate-500 hover:bg-slate-200 hover:text-slate-700"
                                            aria-label={`${t('common.actions.delete')} ${holiday.name}`}
                                        >
                                            <IconX className="h-3 w-3" />
                                        </button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </CardBody>
        </Card>
    );
}

/*
|--------------------------------------------------------------------------
| Dialogs
|--------------------------------------------------------------------------
*/

function PolicyDialog({
    policy,
    calendars,
    options,
    onClose,
}: {
    policy: Policy | null;
    calendars: Calendar[];
    options: Options;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        name: policy?.name ?? '',
        slug: policy?.slug ?? '',
        description: policy?.description ?? '',
        business_calendar_id: policy?.business_calendar_id ?? calendars[0]?.id ?? 0,
        is_active: policy?.is_active ?? true,
        is_default: policy?.is_default ?? false,
        position: policy?.position ?? 100,
        conditions: policy?.conditions ?? emptyConditions(),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const done = { preserveScroll: true, onSuccess: onClose };

        if (policy) {
            form.put(`/admin/sla/policies/${policy.id}`, done);
        } else {
            form.post('/admin/sla/policies', done);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={policy ? t('sla.policies.edit') : t('sla.policies.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('common.labels.name')} error={form.errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                onChange={(event) => {
                                    form.setData('name', event.target.value);

                                    if (!policy) {
                                        form.setData('slug', slugify(event.target.value));
                                    }
                                }}
                            />
                        )}
                    </Field>

                    <Field label={t('sla.policies.slug')} error={form.errors.slug} required>
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

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('sla.calendars.title')} error={form.errors.business_calendar_id} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.business_calendar_id}
                                onChange={(event) =>
                                    form.setData('business_calendar_id', Number(event.target.value))
                                }
                            >
                                {calendars.map((calendar) => (
                                    <option key={calendar.id} value={calendar.id}>
                                        {calendar.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label={t('sla.policies.position')}
                        help={t('sla.policies.position_help')}
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
                </div>

                <div className="space-y-2">
                    <Toggle
                        checked={form.data.is_active}
                        onChange={(value) => form.setData('is_active', value)}
                        label={t('common.labels.active')}
                    />
                    <Toggle
                        checked={form.data.is_default}
                        onChange={(value) => form.setData('is_default', value)}
                        label={t('sla.policies.default')}
                    />
                </div>

                <fieldset className="space-y-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('sla.policies.conditions')}</legend>
                    <p className="text-xs text-slate-500">{t('sla.policies.conditions_help')}</p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {CONDITION_KEYS.map(({ key, options: optionKey }) => {
                            const pool = options[optionKey] as NamedOption[];

                            if (pool.length === 0) return null;

                            return (
                                <Field key={key} label={t(`sla.conditions.${key}`)}>
                                    {() => (
                                        <CheckboxGroup
                                            options={pool.map((option) => ({
                                                value: option.id,
                                                label: option.name,
                                            }))}
                                            selected={form.data.conditions[key] ?? []}
                                            onChange={(values) =>
                                                form.setData('conditions', {
                                                    ...form.data.conditions,
                                                    [key]: values,
                                                })
                                            }
                                            maxHeight="max-h-40"
                                        />
                                    )}
                                </Field>
                            );
                        })}
                    </div>
                </fieldset>
            </form>
        </Modal>
    );
}

function GoalDialog({
    policy,
    goal,
    options,
    onClose,
}: {
    policy: Policy;
    goal: Goal | null;
    options: Options;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const initial = splitDuration(goal?.target_minutes ?? 240);

    const [unit, setUnit] = useState<'minutes' | 'hours' | 'days'>(initial.unit);
    const [amount, setAmount] = useState(initial.value);

    const form = useForm({
        metric: goal?.metric ?? ('first_response' as Metric),
        priority_id: goal?.priority_id ?? null,
        request_type_id: goal?.request_type_id ?? null,
        target_minutes: goal?.target_minutes ?? 240,
        is_active: goal?.is_active ?? true,
        escalations: goal?.escalations ?? ([] as Escalation[]),
    });

    const setDuration = (value: number, nextUnit: 'minutes' | 'hours' | 'days') => {
        setAmount(value);
        setUnit(nextUnit);
        form.setData('target_minutes', toMinutes(value, nextUnit));
    };

    const updateEscalation = (index: number, next: Escalation) => {
        const escalations = [...form.data.escalations];
        escalations[index] = next;
        form.setData('escalations', escalations);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const done = { preserveScroll: true, onSuccess: onClose };

        if (goal) {
            form.put(`/admin/sla/goals/${goal.id}`, done);
        } else {
            form.post(`/admin/sla/policies/${policy.id}/goals`, done);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={goal ? t('sla.goals.edit') : t('sla.goals.add')}
            description={policy.name}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('sla.goals.metric')} help={t(`sla.metrics.${form.data.metric}_help`)} required>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.metric}
                            onChange={(event) => form.setData('metric', event.target.value as Metric)}
                        >
                            {options.metrics.map((metric) => (
                                <option key={metric} value={metric}>
                                    {t(`sla.metrics.${metric}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('sla.goals.target')} help={t('sla.goals.target_help')} error={form.errors.target_minutes} required>
                    {(props) => (
                        <div className="flex gap-2">
                            <TextInput
                                {...props}
                                type="number"
                                min={1}
                                value={amount}
                                onChange={(event) => setDuration(Number(event.target.value), unit)}
                                className="w-28"
                            />
                            <Select
                                value={unit}
                                onChange={(event) =>
                                    setDuration(amount, event.target.value as 'minutes' | 'hours' | 'days')
                                }
                                aria-label={t('sla.goals.target')}
                            >
                                <option value="minutes">{t('sla.goals.unit_minutes', { count: amount })}</option>
                                <option value="hours">{t('sla.goals.unit_hours', { count: amount })}</option>
                                <option value="days">{t('sla.goals.unit_days', { count: amount })}</option>
                            </Select>
                        </div>
                    )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('sla.goals.priority')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.priority_id ?? ''}
                                onChange={(event) =>
                                    form.setData('priority_id', event.target.value ? Number(event.target.value) : null)
                                }
                            >
                                <option value="">{t('sla.goals.any_priority')}</option>
                                {options.priorities.map((priority) => (
                                    <option key={priority.id} value={priority.id}>
                                        {priority.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label={t('sla.goals.request_type')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.request_type_id ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'request_type_id',
                                        event.target.value ? Number(event.target.value) : null,
                                    )
                                }
                            >
                                <option value="">{t('sla.goals.any_request_type')}</option>
                                {options.request_types.map((type) => (
                                    <option key={type.id} value={type.id}>
                                        {type.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <fieldset className="space-y-3 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('sla.escalations.title')}</legend>
                    <p className="text-xs text-slate-500">{t('sla.escalations.description')}</p>

                    {form.data.escalations.length === 0 ? (
                        <p className="text-xs text-slate-500">{t('sla.escalations.empty')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {form.data.escalations.map((escalation, index) => (
                                <li key={index} className="rounded-md border border-slate-200 p-3">
                                    <div className="flex flex-wrap items-end gap-2">
                                        <Field label={t('sla.escalations.at')} className="w-28">
                                            {(props) => (
                                                <TextInput
                                                    {...props}
                                                    type="number"
                                                    min={1}
                                                    max={500}
                                                    value={escalation.at}
                                                    onChange={(event) =>
                                                        updateEscalation(index, {
                                                            ...escalation,
                                                            at: Number(event.target.value),
                                                        })
                                                    }
                                                />
                                            )}
                                        </Field>
                                        <span className="pb-2 text-xs text-slate-500">
                                            {t('sla.escalations.at_suffix')}
                                        </span>
                                        <button
                                            type="button"
                                            className="ml-auto pb-2 text-xs text-slate-500 hover:text-red-600"
                                            onClick={() =>
                                                form.setData(
                                                    'escalations',
                                                    form.data.escalations.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            {t('common.actions.remove')}
                                        </button>
                                    </div>

                                    <div className="mt-2 space-y-2">
                                        {escalation.actions.map((action, actionIndex) => (
                                            <ActionRow
                                                key={actionIndex}
                                                action={action}
                                                options={options}
                                                onChange={(next) => {
                                                    const actions = [...escalation.actions];
                                                    actions[actionIndex] = next;
                                                    updateEscalation(index, { ...escalation, actions });
                                                }}
                                                onRemove={() =>
                                                    updateEscalation(index, {
                                                        ...escalation,
                                                        actions: escalation.actions.filter(
                                                            (_, i) => i !== actionIndex,
                                                        ),
                                                    })
                                                }
                                            />
                                        ))}

                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                updateEscalation(index, {
                                                    ...escalation,
                                                    actions: [
                                                        ...escalation.actions,
                                                        { type: 'notify', to: 'assignee' },
                                                    ],
                                                })
                                            }
                                        >
                                            <IconPlus className="h-3.5 w-3.5" />
                                            {t('sla.escalations.add_action')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={() =>
                            form.setData('escalations', [
                                ...form.data.escalations,
                                { at: 75, actions: [{ type: 'notify', to: 'assignee' }] },
                            ])
                        }
                    >
                        <IconPlus className="h-3.5 w-3.5" />
                        {t('sla.escalations.add')}
                    </Button>
                </fieldset>
            </form>
        </Modal>
    );
}

function ActionRow({
    action,
    options,
    onChange,
    onRemove,
}: {
    action: EscalationAction;
    options: Options;
    onChange: (action: EscalationAction) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslations();

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Select
                value={action.type}
                onChange={(event) => onChange({ type: event.target.value as ActionType })}
                aria-label={t('sla.escalations.actions')}
                className="w-auto"
            >
                {options.actions.map((type) => (
                    <option key={type} value={type}>
                        {t(`sla.escalations.action_types.${type}`)}
                    </option>
                ))}
            </Select>

            {action.type === 'notify' ? (
                <Select
                    value={action.to ?? 'assignee'}
                    onChange={(event) => onChange({ ...action, to: event.target.value })}
                    aria-label={t('sla.escalations.actions')}
                    className="w-auto"
                >
                    {options.notify_targets.map((target) => (
                        <option key={target} value={target}>
                            {t(`sla.escalations.targets.${target}`)}
                        </option>
                    ))}
                </Select>
            ) : null}

            {action.type === 'assign_team' ? (
                <Select
                    value={action.team_id ?? ''}
                    onChange={(event) => onChange({ ...action, team_id: Number(event.target.value) })}
                    aria-label={t('sla.escalations.team')}
                    className="w-auto"
                >
                    <option value="">{t('common.labels.none')}</option>
                    {options.teams.map((team) => (
                        <option key={team.id} value={team.id}>
                            {team.name}
                        </option>
                    ))}
                </Select>
            ) : null}

            <button
                type="button"
                onClick={onRemove}
                className="rounded p-1 text-slate-600 hover:bg-slate-100 hover:text-red-600"
                aria-label={t('common.actions.remove')}
            >
                <IconX className="h-3.5 w-3.5" />
            </button>
        </div>
    );
}

function CalendarDialog({
    calendar,
    options,
    onClose,
}: {
    calendar: Calendar | null;
    options: Options;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        name: calendar?.name ?? '',
        slug: calendar?.slug ?? '',
        description: calendar?.description ?? '',
        timezone: calendar?.timezone ?? 'Europe/Amsterdam',
        is_default: calendar?.is_default ?? false,
        working_hours:
            calendar?.working_hours ??
            (Object.fromEntries(
                options.days.map((day) => [day, ['sat', 'sun'].includes(day) ? [] : [['09:00', '17:00']]]),
            ) as WorkingHours),
    });

    const setBlock = (day: string, index: number, position: 0 | 1, value: string) => {
        const blocks = (form.data.working_hours[day] ?? []).map((block, i) =>
            i === index ? (position === 0 ? [value, block[1]] : [block[0], value]) : block,
        );

        form.setData('working_hours', { ...form.data.working_hours, [day]: blocks });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const done = { preserveScroll: true, onSuccess: onClose };

        if (calendar) {
            form.put(`/admin/sla/calendars/${calendar.id}`, done);
        } else {
            form.post('/admin/sla/calendars', done);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={calendar ? t('sla.calendars.edit') : t('sla.calendars.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('common.labels.name')} error={form.errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                onChange={(event) => {
                                    form.setData('name', event.target.value);

                                    if (!calendar) {
                                        form.setData('slug', slugify(event.target.value));
                                    }
                                }}
                            />
                        )}
                    </Field>

                    <Field
                        label={t('sla.calendars.timezone')}
                        help={t('sla.calendars.timezone_help')}
                        error={form.errors.timezone}
                        required
                    >
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.timezone}
                                onChange={(event) => form.setData('timezone', event.target.value)}
                            >
                                {options.timezones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <Toggle
                    checked={form.data.is_default}
                    onChange={(value) => form.setData('is_default', value)}
                    label={t('sla.calendars.default')}
                />

                <fieldset className="space-y-2 border-t border-slate-100 pt-4">
                    <legend className="text-sm font-medium text-slate-800">{t('sla.calendars.working_hours')}</legend>
                    <p className="text-xs text-slate-500">{t('sla.calendars.working_hours_help')}</p>
                    <p className="text-xs text-slate-500">{t('sla.calendars.midnight_hint')}</p>

                    <ul className="space-y-1.5">
                        {options.days.map((day) => {
                            const blocks = form.data.working_hours[day] ?? [];

                            return (
                                <li key={day} className="flex flex-wrap items-center gap-2">
                                    <span className="w-24 shrink-0 text-sm text-slate-600">
                                        {t(`sla.days.${day}`)}
                                    </span>

                                    {blocks.map((block, index) => (
                                        <span key={index} className="flex items-center gap-1">
                                            <TextInput
                                                type="time"
                                                value={block[0]}
                                                onChange={(event) => setBlock(day, index, 0, event.target.value)}
                                                className="w-28"
                                                aria-label={`${t(`sla.days.${day}`)} ${t('sla.calendars.opens')}`}
                                            />
                                            <span className="text-slate-500">–</span>
                                            <TextInput
                                                type="time"
                                                value={block[1]}
                                                onChange={(event) => setBlock(day, index, 1, event.target.value)}
                                                className="w-28"
                                                aria-label={`${t(`sla.days.${day}`)} ${t('sla.calendars.closes')}`}
                                            />
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    form.setData('working_hours', {
                                                        ...form.data.working_hours,
                                                        [day]: blocks.filter((_, i) => i !== index),
                                                    })
                                                }
                                                className="rounded p-1 text-slate-600 hover:bg-slate-100 hover:text-red-600"
                                                aria-label={t('common.actions.remove')}
                                            >
                                                <IconX className="h-3.5 w-3.5" />
                                            </button>
                                        </span>
                                    ))}

                                    {blocks.length === 0 ? (
                                        <span className="text-xs text-slate-500">{t('sla.calendars.closed')}</span>
                                    ) : null}

                                    {blocks.length < 4 ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                form.setData('working_hours', {
                                                    ...form.data.working_hours,
                                                    [day]: [...blocks, ['09:00', '17:00']],
                                                })
                                            }
                                        >
                                            {t('sla.calendars.add_block')}
                                        </Button>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                </fieldset>
            </form>
        </Modal>
    );
}

function HolidayDialog({ calendar, onClose }: { calendar: Calendar; onClose: () => void }) {
    const { t } = useTranslations();

    const form = useForm({
        name: '',
        date: '',
        is_recurring: false,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(`/admin/sla/calendars/${calendar.id}/holidays`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('sla.holidays.add')}
            description={calendar.name}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label={t('sla.holidays.name')} error={form.errors.name} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('sla.holidays.date')} error={form.errors.date} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="date"
                            value={form.data.date}
                            onChange={(event) => form.setData('date', event.target.value)}
                        />
                    )}
                </Field>

                <div>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <Checkbox
                            checked={form.data.is_recurring}
                            onChange={(event) => form.setData('is_recurring', event.target.checked)}
                        />
                        {t('sla.holidays.recurring')}
                    </label>
                    <p className="mt-1 text-xs text-slate-500">{t('sla.holidays.recurring_help')}</p>
                </div>
            </form>
        </Modal>
    );
}
