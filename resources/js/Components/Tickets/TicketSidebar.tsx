import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { PriorityBadge, LabelChip } from '@/Components/Tickets/Badges';
import { tintedChip } from '@/lib/contrast';
import { IconPlus, IconX } from '@/Components/Icons';
import {
    Avatar,
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Select,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import type { TicketDetail, TicketOptions } from '@/types/tickets';

/**
 * The properties column of the ticket detail page. Every control posts
 * immediately — a service desk agent should never have to find a "save"
 * button to reassign a ticket.
 */
export function TicketSidebar({
    ticket,
    options,
    can,
    isWatching,
}: {
    ticket: TicketDetail;
    options: TicketOptions;
    can: Record<string, boolean>;
    isWatching: boolean;
}) {
    const { t, locale } = useTranslations();
    const [linking, setLinking] = useState(false);

    const patch = (payload: Record<string, string | number | boolean | null | number[]>) => {
        router.put(`/agent/tickets/${ticket.key}`, payload, { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <Card>
                <CardHeader title={t('tickets.detail.properties')} />
                <CardBody className="space-y-3.5">
                    <Field label={t('tickets.fields.assignee')}>
                        {(props) => (
                            <div className="flex gap-2">
                                <Select
                                    {...props}
                                    value={String(ticket.assignee?.id ?? '')}
                                    disabled={!can.assign}
                                    onChange={(event) =>
                                        router.put(
                                            `/agent/tickets/${ticket.key}/assignee`,
                                            { assignee_id: event.target.value ? Number(event.target.value) : null },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <option value="">{t('common.labels.unassigned')}</option>
                                    {(options.assignees ?? []).map((agent) => (
                                        <option key={agent.id} value={agent.id}>
                                            {agent.name}
                                        </option>
                                    ))}
                                </Select>

                                {can.assign && !ticket.assignee ? (
                                    <Button
                                        variant="secondary"
                                        size="md"
                                        className="shrink-0"
                                        onClick={() =>
                                            router.post(`/agent/tickets/${ticket.key}/claim`, {}, { preserveScroll: true })
                                        }
                                    >
                                        {t('tickets.actions.claim')}
                                    </Button>
                                ) : null}
                            </div>
                        )}
                    </Field>

                    <Field label={t('tickets.fields.priority')}>
                        {(props) =>
                            can.update ? (
                                <Select
                                    {...props}
                                    value={String(ticket.priority?.id ?? '')}
                                    onChange={(event) => patch({ priority_id: Number(event.target.value) })}
                                >
                                    {options.priorities.map((priority) => (
                                        <option key={priority.id} value={priority.id}>
                                            {priority.name}
                                        </option>
                                    ))}
                                </Select>
                            ) : (
                                <PriorityBadge priority={ticket.priority} />
                            )
                        }
                    </Field>

                    <Field label={t('tickets.fields.team')}>
                        {(props) =>
                            can.update ? (
                                <Select
                                    {...props}
                                    value={String(ticket.team?.id ?? '')}
                                    onChange={(event) =>
                                        patch({ team_id: event.target.value ? Number(event.target.value) : null })
                                    }
                                >
                                    <option value="">{t('common.labels.none')}</option>
                                    {options.teams.map((team) => (
                                        <option key={team.id} value={team.id}>
                                            {team.name}
                                        </option>
                                    ))}
                                </Select>
                            ) : (
                                <p className="text-sm text-slate-700">{ticket.team?.name ?? '—'}</p>
                            )
                        }
                    </Field>

                    <div>
                        <p className="text-sm font-medium text-slate-700">{t('tickets.fields.labels')}</p>
                        {can.update ? (
                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {options.labels.map((label) => {
                                    const selected = ticket.labels.some((item) => item.id === label.id);

                                    return (
                                        <button
                                            key={label.id}
                                            type="button"
                                            aria-pressed={selected}
                                            onClick={() =>
                                                patch({
                                                    label_ids: selected
                                                        ? ticket.labels.filter((item) => item.id !== label.id).map((item) => item.id)
                                                        : [...ticket.labels.map((item) => item.id), label.id],
                                                })
                                            }
                                            className="rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 transition-opacity"
                                            style={{
                                                backgroundColor: selected
                                                    ? tintedChip(label.color).backgroundColor
                                                    : 'transparent',
                                                color: selected ? tintedChip(label.color).color : '#64748b',
                                                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                                                ['--tw-ring-color' as any]: selected ? label.color : '#e2e8f0',
                                            }}
                                        >
                                            {label.name}
                                        </button>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="mt-1.5 flex flex-wrap gap-1">
                                {ticket.labels.map((label) => (
                                    <LabelChip key={label.id} label={label} />
                                ))}
                            </div>
                        )}
                    </div>

                    <dl className="space-y-1.5 border-t border-slate-100 pt-3 text-xs text-slate-500">
                        <Row label={t('tickets.columns.source')} value={t(`tickets.source.${ticket.source}`)} />
                        <Row label={t('tickets.columns.created_at')} value={formatDateTime(ticket.created_at, locale)} />
                        {ticket.first_response_at ? (
                            <Row
                                label={t('tickets.detail.first_response')}
                                value={formatDateTime(ticket.first_response_at, locale)}
                            />
                        ) : null}
                        {ticket.resolved_at ? (
                            <Row label={t('tickets.detail.resolved')} value={formatDateTime(ticket.resolved_at, locale)} />
                        ) : null}
                        {ticket.reopen_count > 0 ? (
                            <Row
                                label=""
                                value={t('tickets.detail.reopened', { count: ticket.reopen_count })}
                            />
                        ) : null}
                        <Row label={t('tickets.fields.workflow')} value={ticket.workflow.name} />
                    </dl>
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title={t('tickets.fields.watchers')}
                    actions={
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    `/agent/tickets/${ticket.key}/watch`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            disabled={isWatching}
                        >
                            {isWatching ? t('tickets.actions.unwatch') : t('tickets.actions.watch')}
                        </Button>
                    }
                />
                <CardBody>
                    {ticket.watchers.length === 0 ? (
                        <p className="text-xs text-slate-500">{t('tickets.detail.no_watchers')}</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {ticket.watchers.map((watcher) => (
                                <li key={watcher.id} className="flex items-center gap-2">
                                    <Avatar
                                        name={watcher.name}
                                        initials={watcher.initials}
                                        color={watcher.avatar_color}
                                        size="xs"
                                    />
                                    <span className="min-w-0 flex-1 truncate text-sm text-slate-700">{watcher.name}</span>
                                    {can.update ? (
                                        <button
                                            type="button"
                                            aria-label={t('common.actions.remove')}
                                            onClick={() =>
                                                router.delete(
                                                    `/agent/tickets/${ticket.key}/watchers/${watcher.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="text-slate-500 hover:text-red-600"
                                        >
                                            <IconX className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title={t('tickets.fields.links')}
                    actions={
                        can.link ? (
                            <Button
                                size="sm"
                                variant="ghost"
                                // An icon is aria-hidden, so a button holding
                                // nothing else has no accessible name at all —
                                // a screen reader announces "button" and stops.
                                aria-label={t('tickets.actions.link')}
                                title={t('tickets.actions.link')}
                                aria-expanded={linking}
                                onClick={() => setLinking((value) => !value)}
                            >
                                <IconPlus className="h-3.5 w-3.5" />
                            </Button>
                        ) : null
                    }
                />
                <CardBody className="space-y-2">
                    {linking ? <LinkForm ticketKey={ticket.key} onDone={() => setLinking(false)} /> : null}

                    {ticket.links.length === 0 ? (
                        <p className="text-xs text-slate-500">{t('tickets.detail.no_links')}</p>
                    ) : (
                        <ul className="space-y-1.5">
                            {ticket.links.map((link) => (
                                <li key={`${link.type}-${link.ticket.key}`} className="text-sm">
                                    <span className="text-xs text-slate-500">{t(`tickets.link_types.${link.type}`)}</span>
                                    <a
                                        href={`/agent/tickets/${link.ticket.key}`}
                                        className="ml-1 font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                                    >
                                        {link.ticket.key}
                                    </a>
                                    <span className="ml-1 text-slate-600">{link.ticket.subject}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardBody>
            </Card>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-2">
            <dt>{label}</dt>
            <dd className="text-right text-slate-700">{value}</dd>
        </div>
    );
}

function LinkForm({ ticketKey, onDone }: { ticketKey: string; onDone: () => void }) {
    const { t } = useTranslations();
    const form = useForm({ related_key: '', type: 'relates' });

    return (
        <form
            className="space-y-2 rounded-lg border border-slate-200 p-2.5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/agent/tickets/${ticketKey}/links`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onDone();
                    },
                });
            }}
        >
            <Select
                value={form.data.type}
                aria-label={t('tickets.actions.link')}
                onChange={(event) => form.setData('type', event.target.value)}
            >
                {['relates', 'duplicates', 'blocks', 'causes', 'parent'].map((type) => (
                    <option key={type} value={type}>
                        {t(`tickets.link_types.${type}`)}
                    </option>
                ))}
            </Select>

            <TextInput
                value={form.data.related_key}
                placeholder="SUP-1042"
                aria-label={t('tickets.columns.key')}
                className="font-mono"
                onChange={(event) => form.setData('related_key', event.target.value.toUpperCase())}
            />

            {form.errors.related_key ? <p className="text-xs text-red-600">{form.errors.related_key}</p> : null}

            <div className="flex justify-end gap-1.5">
                <Button size="sm" variant="secondary" onClick={onDone}>
                    {t('common.actions.cancel')}
                </Button>
                <Button size="sm" type="submit" disabled={form.processing}>
                    {t('common.actions.add')}
                </Button>
            </div>
        </form>
    );
}
