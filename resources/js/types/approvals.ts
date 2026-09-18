/**
 * Shapes the approval screens receive from the server. These mirror
 * `ApprovalRequest::toSummaryArray()` / `toDetailArray()`,
 * `ApprovalDecision::toSummaryArray()` and the two admin payloads — when one
 * changes, change the other.
 */

import type { UserSummary } from '@/types/tickets';

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type DecisionOutcome = 'pending' | 'approved' | 'rejected' | 'skipped';

export type StepMode = 'any' | 'all';

export type StepState = 'open' | 'waiting' | 'approved' | 'rejected' | 'cancelled';

export type ApproverType = 'users' | 'team' | 'role' | 'manager' | 'field';

export interface ApprovalDecision {
    id: number;
    position: number;
    step_name: string | null;
    mode: StepMode;
    decision: DecisionOutcome;
    comment: string | null;
    source: string | null;
    decided_at: string | null;
    notified_at: string | null;
    approver: UserSummary | null;
}

export interface ApprovalStepView {
    position: number;
    name: string | null;
    mode: StepMode;
    state: StepState;
    decisions: ApprovalDecision[];
}

export interface Approval {
    id: number;
    subject: string;
    reason: string | null;
    status: ApprovalStatus;
    current_position: number;
    is_overdue: boolean;
    due_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    workflow: { id: number; name: string } | null;
    requested_by: UserSummary | null;
    ticket_key?: string | null;
    steps: ApprovalStepView[];
    /** The decision row this reader may answer, if any. Decided server-side. */
    my_decision_id?: number | null;
    can_cancel?: boolean;
    ticket?: {
        key: string | null;
        subject: string | null;
        description?: string | null;
        requester?: UserSummary | null;
    };
}

export interface ApprovalWorkflowStep {
    id: number;
    name: string | null;
    label: string;
    mode: StepMode;
    approver_type: ApproverType;
    approver_ids: number[];
    approver_field: string | null;
    due_hours: number | null;
    position: number;
}

export interface ApprovalWorkflowSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_active: boolean;
    shape: 'single' | 'parallel' | 'sequential' | 'empty';
    step_count: number | null;
}

export interface ApprovalWorkflowAdmin extends ApprovalWorkflowSummary {
    name_translations: Record<string, string>;
    instructions: string | null;
    request_type_count: number | null;
    steps: ApprovalWorkflowStep[];
}

export interface ApprovalAdminOptions {
    modes: StepMode[];
    approver_types: ApproverType[];
    teams: { id: number; name: string }[];
    roles: { id: number; name: string }[];
    /** Only the people the saved steps already name — the rest are searched for. */
    people: UserSummary[];
}
