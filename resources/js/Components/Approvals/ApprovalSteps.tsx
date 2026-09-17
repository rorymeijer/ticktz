import { StepBadge } from '@/Components/Approvals/ApprovalBadge';
import { IconCheckCircle, IconClock, IconMail, IconX } from '@/Components/Icons';
import { Avatar } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { relativeTime } from '@/lib/datetime';
import type { ApprovalDecision, ApprovalStepView } from '@/types/approvals';
import { RichText } from '@/Components/RichText/RichText';

/**
 * An approval, step by step.
 *
 * Shows who was asked and what each of them said — including the people who
 * never answered, because "waiting on Anouk" and "nothing has happened" are
 * very different things and only one of them tells you who to chase.
 */
export function ApprovalSteps({ steps }: { steps: ApprovalStepView[] }) {
    const { t } = useTranslations();

    if (steps.length === 0) {
        return null;
    }

    return (
        <ol className="space-y-3">
            {steps.map((step) => (
                <li key={step.position}>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs font-semibold text-slate-700">
                            {step.name || t('approvals.steps.number', { number: step.position + 1 })}
                        </span>
                        <StepBadge state={step.state} />
                        {step.decisions.length > 1 ? (
                            <span className="text-[11px] text-slate-500">{t(`approvals.mode.${step.mode}_short`)}</span>
                        ) : null}
                    </div>

                    <ul className="mt-1.5 space-y-1.5">
                        {step.decisions.map((decision) => (
                            <li key={decision.id}>
                                <DecisionRow decision={decision} dimmed={step.state === 'waiting'} />
                            </li>
                        ))}
                    </ul>
                </li>
            ))}
        </ol>
    );
}

function DecisionRow({ decision, dimmed }: { decision: ApprovalDecision; dimmed: boolean }) {
    const { t } = useTranslations();

    const Icon =
        decision.decision === 'approved' ? IconCheckCircle : decision.decision === 'rejected' ? IconX : IconClock;

    const tone =
        decision.decision === 'approved'
            ? 'text-emerald-600'
            : decision.decision === 'rejected'
              ? 'text-red-600'
              : 'text-slate-300';

    return (
        <div className={cn('flex items-start gap-2', dimmed && 'opacity-60')}>
            <Icon className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', tone)} />

            {decision.approver ? (
                <Avatar
                    name={decision.approver.name}
                    initials={decision.approver.initials}
                    color={decision.approver.avatar_color}
                    size="xs"
                />
            ) : null}

            <div className="min-w-0 flex-1">
                <p className="text-xs text-slate-700">
                    {decision.approver?.name}
                    {decision.decision !== 'pending' ? (
                        <span className="text-slate-500">
                            {' · '}
                            {t(`approvals.decision.${decision.decision}`).toLowerCase()}
                        </span>
                    ) : null}
                </p>

                {decision.comment ? (
                    <RichText html={decision.comment} className="mt-0.5 text-xs italic text-slate-500" />
                ) : null}

                <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                    {decision.decided_at
                        ? t('approvals.ticket.answered', { time: relativeTime(decision.decided_at, t) })
                        : decision.notified_at
                          ? t('approvals.ticket.asked', { time: relativeTime(decision.notified_at, t) })
                          : null}
                    {/* Worth surfacing: a decision that arrived through a
                        one-time link is a different kind of evidence from one
                        somebody clicked while signed in. */}
                    {decision.source === 'email' ? (
                        <span className="inline-flex items-center gap-1">
                            <IconMail className="h-3 w-3" />
                            {t('approvals.ticket.source_email')}
                        </span>
                    ) : null}
                </p>
            </div>
        </div>
    );
}
