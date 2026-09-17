import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { PriorityBadge, StatusBadge } from '@/Components/Tickets/Badges';
import { ReplyBox } from '@/Components/Tickets/ReplyBox';
import { Timeline } from '@/Components/Tickets/Timeline';
import { ApprovalPanel } from '@/Components/Tickets/ApprovalPanel';
import { AssetPanel } from '@/Components/Tickets/AssetPanel';
import { KbPanel } from '@/Components/Tickets/KbPanel';
import { SlaPanel } from '@/Components/Tickets/SlaPanel';
import { TicketSidebar } from '@/Components/Tickets/TicketSidebar';
import { Avatar, Badge, Button, Card, CardBody, CardHeader, Modal, Textarea } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import type { Approval, ApprovalWorkflowSummary } from '@/types/approvals';
import type { AssetSummary } from '@/types/assets';
import type { KbArticleSummary } from '@/types/kb';
import type { TicketDetail, TicketOptions, TimelineItem, TransitionOption } from '@/types/tickets';

export default function TicketShow({
    ticket,
    timeline,
    transitions,
    options,
    can,
    isWatching,
    kbArticles,
    approvals,
    approvalWorkflows,
    assets,
}: {
    ticket: TicketDetail;
    timeline: TimelineItem[];
    transitions: TransitionOption[];
    options: TicketOptions;
    can: Record<string, boolean>;
    isWatching: boolean;
    kbArticles: KbArticleSummary[];
    approvals: Approval[];
    approvalWorkflows: ApprovalWorkflowSummary[];
    assets: AssetSummary[];
}) {
    const { t, locale } = useTranslations();
    const [pendingTransition, setPendingTransition] = useState<TransitionOption | null>(null);

    return (
        <AppLayout
            title={`${ticket.key} — ${ticket.subject}`}
            header={
                <span className="flex items-center gap-2">
                    <Link href="/agent/tickets" className="text-slate-500 hover:text-slate-600">
                        {t('tickets.title')}
                    </Link>
                    <span aria-hidden="true" className="text-slate-300">
                        /
                    </span>
                    <span className="font-mono">{ticket.key}</span>
                </span>
            }
            actions={
                <div className="flex flex-wrap items-center gap-1.5">
                    {transitions.map((transition) => (
                        <Button
                            key={transition.id}
                            size="sm"
                            variant={transition.to_status?.category === 'resolved' ? 'primary' : 'secondary'}
                            onClick={() =>
                                transition.requires_comment
                                    ? setPendingTransition(transition)
                                    : router.post(
                                          `/agent/tickets/${ticket.key}/transition`,
                                          { status_id: transition.to_status_id },
                                          { preserveScroll: true },
                                      )
                            }
                        >
                            {transition.name}
                        </Button>
                    ))}
                </div>
            }
        >
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0 space-y-4">
                    <Card>
                        <CardBody>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={ticket.status} />
                                <PriorityBadge priority={ticket.priority} />
                                <Badge tone="slate">{t(`tickets.source.${ticket.source}`)}</Badge>
                                {ticket.queue ? <Badge tone="blue">{ticket.queue.name}</Badge> : null}
                            </div>

                            <h2 className="mt-2.5 text-lg font-semibold tracking-tight text-slate-900">
                                {ticket.subject}
                            </h2>

                            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                {ticket.requester ? (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Avatar
                                            name={ticket.requester.name}
                                            initials={ticket.requester.initials}
                                            color={ticket.requester.avatar_color}
                                            size="xs"
                                        />
                                        {t('tickets.detail.opened_by', { name: ticket.requester.name })}
                                    </span>
                                ) : null}
                                <span aria-hidden="true">·</span>
                                <time dateTime={ticket.created_at ?? undefined}>
                                    {formatDateTime(ticket.created_at, locale)}
                                </time>
                                {ticket.organization ? (
                                    <>
                                        <span aria-hidden="true">·</span>
                                        <span>{ticket.organization.name}</span>
                                    </>
                                ) : null}
                            </div>

                            {ticket.description ? (
                                <div className="mt-4 whitespace-pre-wrap break-words border-t border-slate-100 pt-4 text-sm leading-6 text-slate-700">
                                    {ticket.description}
                                </div>
                            ) : null}
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title={t('tickets.timeline.title')} />
                        <Timeline items={timeline} />
                    </Card>

                    <ReplyBox ticketKey={ticket.key} canReply={can.comment} canNote={can.comment_internal} />
                </div>

                <div className="space-y-4">
                    <SlaPanel ticket={ticket} />
                    {/* Above the properties: when a ticket is held by an
                        approval, that is the first thing an agent needs to
                        know about it. */}
                    {approvals.length > 0 || can.approve ? (
                        <ApprovalPanel
                            ticketKey={ticket.key}
                            approvals={approvals}
                            workflows={approvalWorkflows}
                            assignees={options.assignees ?? []}
                            canRequest={can.approve && can.update}
                        />
                    ) : null}
                    <TicketSidebar ticket={ticket} options={options} can={can} isWatching={isWatching} />
                    {can.assets ? (
                        <AssetPanel
                            ticketKey={ticket.key}
                            requesterId={ticket.requester?.id ?? null}
                            assets={assets}
                            canLink={can.assets && can.update}
                        />
                    ) : null}
                    {can.kb ? <KbPanel ticketKey={ticket.key} articles={kbArticles} canLink={can.update} /> : null}
                </div>
            </div>

            {pendingTransition ? (
                <TransitionDialog
                    ticketKey={ticket.key}
                    transition={pendingTransition}
                    onClose={() => setPendingTransition(null)}
                />
            ) : null}
        </AppLayout>
    );
}

/**
 * Some transitions demand a comment — "Resolved" without saying what you did
 * helps nobody. The workflow declares it; this dialog collects it.
 */
function TransitionDialog({
    ticketKey,
    transition,
    onClose,
}: {
    ticketKey: string;
    transition: TransitionOption;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({ status_id: transition.to_status_id, comment: '' });

    return (
        <Modal
            open
            onClose={onClose}
            title={transition.name}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || form.data.comment.trim() === ''}
                        onClick={() =>
                            form.post(`/agent/tickets/${ticketKey}/transition`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {t('common.actions.confirm')}
                    </Button>
                </>
            }
        >
            <Textarea
                value={form.data.comment}
                onChange={(event) => form.setData('comment', event.target.value)}
                rows={4}
                autoFocus
                aria-label={t('tickets.actions.reply')}
                placeholder={t('tickets.placeholders.reply')}
            />
            {form.errors.comment ? <p className="mt-1 text-xs text-red-600">{form.errors.comment}</p> : null}
        </Modal>
    );
}
