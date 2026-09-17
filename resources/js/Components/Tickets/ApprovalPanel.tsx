import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { ApprovalBadge } from '@/Components/Approvals/ApprovalBadge';
import { ApprovalSteps } from '@/Components/Approvals/ApprovalSteps';
import { DecideButtons } from '@/Components/Approvals/DecideDialog';
import { IconAlert, IconLock } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    CheckboxGroup,
    ConfirmDialog,
    Field,
    Modal,
    Select,
    TextInput,
    Textarea,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';
import type { Approval, ApprovalWorkflowSummary } from '@/types/approvals';
import type { UserSummary } from '@/types/tickets';

/**
 * The approval beside a ticket.
 *
 * Shows the newest run in full — that is the one the transition gate reads —
 * and everything before it collapsed to a line, because a ticket that has been
 * round the loop twice is exactly the ticket whose history somebody needs.
 */
export function ApprovalPanel({
    ticketKey,
    approvals,
    workflows,
    assignees,
    canRequest,
}: {
    ticketKey: string;
    approvals: Approval[];
    workflows: ApprovalWorkflowSummary[];
    assignees: UserSummary[];
    canRequest: boolean;
}) {
    const { t } = useTranslations();
    const [asking, setAsking] = useState(false);
    const [cancelling, setCancelling] = useState<Approval | null>(null);

    const [current, ...previous] = approvals;

    return (
        <Card>
            <CardHeader
                title={t('approvals.ticket.title')}
                actions={
                    canRequest ? (
                        <Button size="sm" variant="ghost" onClick={() => setAsking(true)}>
                            {t('approvals.actions.request')}
                        </Button>
                    ) : null
                }
            />

            {current ? (
                <CardBody className="space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <ApprovalBadge status={current.status} />
                        {current.is_overdue ? (
                            <Badge tone="red">
                                <IconAlert className="h-3 w-3" />
                                {t('approvals.ticket.overdue')}
                            </Badge>
                        ) : null}
                        {current.workflow ? (
                            <span className="text-xs text-slate-500">{current.workflow.name}</span>
                        ) : null}
                    </div>

                    {/* The gate, said out loud. An agent looking at a ticket
                        whose buttons are refusing them should not have to
                        work out why from the error message. */}
                    {current.status === 'pending' ? (
                        <p className="flex items-start gap-1.5 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800">
                            <IconLock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            {t('approvals.ticket.blocked')}
                        </p>
                    ) : null}

                    {current.status === 'rejected' ? (
                        <p className="rounded-lg bg-red-50 p-2.5 text-xs text-red-800">
                            {t('approvals.ticket.rejected')}
                        </p>
                    ) : null}

                    <div>
                        <p className="text-sm font-medium text-slate-900">{current.subject}</p>
                        {current.reason ? (
                            <p className="mt-0.5 whitespace-pre-wrap text-xs leading-5 text-slate-500">
                                {current.reason}
                            </p>
                        ) : null}
                    </div>

                    <ApprovalSteps steps={current.steps} />

                    {current.my_decision_id ? (
                        <div className="border-t border-slate-100 pt-3">
                            <DecideButtons decisionId={current.my_decision_id} subject={current.subject} />
                        </div>
                    ) : null}

                    {current.can_cancel ? (
                        <Button size="sm" variant="ghost" onClick={() => setCancelling(current)}>
                            {t('approvals.actions.cancel')}
                        </Button>
                    ) : null}

                    {previous.length > 0 ? (
                        <ul className="space-y-1 border-t border-slate-100 pt-3">
                            {previous.map((approval) => (
                                <li key={approval.id} className="flex items-center gap-2 text-xs text-slate-500">
                                    <ApprovalBadge status={approval.status} />
                                    <span className="min-w-0 flex-1 truncate">{approval.subject}</span>
                                    <span>{relativeTime(approval.completed_at ?? approval.created_at, t)}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </CardBody>
            ) : (
                <CardBody className="text-sm text-slate-500">{t('approvals.ticket.none')}</CardBody>
            )}

            {asking ? (
                <RequestDialog
                    ticketKey={ticketKey}
                    workflows={workflows}
                    assignees={assignees}
                    onClose={() => setAsking(false)}
                />
            ) : null}

            <ConfirmDialog
                open={cancelling !== null}
                message={t('approvals.actions.cancel')}
                confirmLabel={t('approvals.actions.cancel')}
                onCancel={() => setCancelling(null)}
                onConfirm={() => {
                    if (!cancelling) return;
                    router.post(
                        `/approvals/${cancelling.id}/cancel`,
                        {},
                        { preserveScroll: true, onFinish: () => setCancelling(null) },
                    );
                }}
            />
        </Card>
    );
}

/**
 * Raising an approval: run a configured workflow, or ask named people now.
 *
 * Both, because an agent who has to create an approval workflow before they
 * can ask a manager a yes/no question will ask them in a chat window instead,
 * and the answer will not be on the ticket.
 */
function RequestDialog({
    ticketKey,
    workflows,
    assignees,
    onClose,
}: {
    ticketKey: string;
    workflows: ApprovalWorkflowSummary[];
    assignees: UserSummary[];
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({
        approval_workflow_id: '',
        approver_ids: [] as number[],
        mode: 'any',
        subject: '',
        reason: '',
    });

    const usingWorkflow = form.data.approval_workflow_id !== '';

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={t('approvals.actions.request')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || (!usingWorkflow && form.data.approver_ids.length === 0)}
                        onClick={() =>
                            form.post(`/agent/tickets/${ticketKey}/approvals`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {t('approvals.actions.request')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('approvals.fields.workflow')} error={form.errors.approval_workflow_id}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.approval_workflow_id}
                            onChange={(event) => form.setData('approval_workflow_id', event.target.value)}
                        >
                            <option value="">{t('approvals.fields.workflow_none')}</option>
                            {workflows.map((workflow) => (
                                <option key={workflow.id} value={workflow.id}>
                                    {workflow.name} · {t(`approvals.shape.${workflow.shape}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                {!usingWorkflow ? (
                    <>
                        <Field label={t('approvals.fields.approvers')} error={form.errors.approver_ids}>
                            {() => (
                                <CheckboxGroup
                                    options={assignees.map((user) => ({
                                        value: user.id,
                                        label: user.name,
                                        description: user.email,
                                    }))}
                                    selected={form.data.approver_ids}
                                    onChange={(values) => form.setData('approver_ids', values as number[])}
                                />
                            )}
                        </Field>

                        {form.data.approver_ids.length > 1 ? (
                            <Field label={t('approvals.fields.approvers')} error={form.errors.mode}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.mode}
                                        onChange={(event) => form.setData('mode', event.target.value)}
                                    >
                                        <option value="any">{t('approvals.mode.any')}</option>
                                        <option value="all">{t('approvals.mode.all')}</option>
                                    </Select>
                                )}
                            </Field>
                        ) : null}
                    </>
                ) : null}

                <Field label={t('approvals.fields.subject')} error={form.errors.subject}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.subject}
                            maxLength={255}
                            onChange={(event) => form.setData('subject', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('approvals.fields.reason')} error={form.errors.reason}>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={3}
                            maxLength={2000}
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                    )}
                </Field>
            </div>
        </Modal>
    );
}
