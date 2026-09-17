import { Badge, type BadgeTone } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { ApprovalStatus, DecisionOutcome, StepState } from '@/types/approvals';

const statusTones: Record<ApprovalStatus, BadgeTone> = {
    pending: 'amber',
    approved: 'green',
    rejected: 'red',
    cancelled: 'slate',
};

const decisionTones: Record<DecisionOutcome, BadgeTone> = {
    pending: 'slate',
    approved: 'green',
    rejected: 'red',
    skipped: 'slate',
};

const stepTones: Record<StepState, BadgeTone> = {
    open: 'amber',
    waiting: 'slate',
    approved: 'green',
    rejected: 'red',
    cancelled: 'slate',
};

export function ApprovalBadge({ status }: { status: ApprovalStatus }) {
    const { t } = useTranslations();

    return <Badge tone={statusTones[status]}>{t(`approvals.status.${status}`)}</Badge>;
}

export function DecisionBadge({ decision }: { decision: DecisionOutcome }) {
    const { t } = useTranslations();

    return <Badge tone={decisionTones[decision]}>{t(`approvals.decision.${decision}`)}</Badge>;
}

export function StepBadge({ state }: { state: StepState }) {
    const { t } = useTranslations();

    return <Badge tone={stepTones[state]}>{t(`approvals.steps.state.${state}`)}</Badge>;
}
