/**
 * Shapes the ticket screens receive from the server. These mirror the
 * `toSummaryArray()` / `toListArray()` methods on the Eloquent models — when
 * one changes, change the other.
 */

export interface UserSummary {
    id: number;
    name: string;
    email: string;
    initials: string;
    avatar_color: string;
}

export interface StatusSummary {
    id: number;
    name: string;
    slug: string;
    category: 'new' | 'open' | 'pending' | 'resolved' | 'closed';
    color: string;
    is_open: boolean;
}

export interface PrioritySummary {
    id: number;
    name: string;
    slug: string;
    level: number;
    color: string;
}

export interface LabelSummary {
    id: number;
    name: string;
    slug: string;
    color: string;
}

export interface AttachmentSummary {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    is_image: boolean;
    is_internal: boolean;
    url: string;
    created_at: string | null;
}

export interface TicketListItem {
    id: number;
    key: string;
    subject: string;
    status: StatusSummary | null;
    priority: PrioritySummary | null;
    requester: UserSummary | null;
    assignee: UserSummary | null;
    team: { id: number; name: string } | null;
    organization: { id: number; name: string } | null;
    labels: LabelSummary[];
    source: string;
    created_at: string | null;
    updated_at: string | null;
    last_activity_at: string | null;
}

export interface TicketLinkItem {
    id: number;
    type: string;
    ticket: { key: string; subject: string; status: StatusSummary | null };
}

export interface TicketDetail extends TicketListItem {
    description: string | null;
    queue: { id: number; name: string; slug: string } | null;
    workflow: { id: number; name: string };
    watchers: UserSummary[];
    first_response_at: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    reopen_count: number;
    links: TicketLinkItem[];
}

export interface TimelineComment {
    id: number;
    type: 'comment';
    body: string;
    is_internal: boolean;
    source: string;
    author: Partial<UserSummary> & { name: string };
    edited_at: string | null;
    created_at: string | null;
    attachments: AttachmentSummary[];
}

export interface TimelineEvent {
    id: string;
    type: 'event';
    event: string;
    description: string | null;
    actor: Partial<UserSummary> & { name: string };
    created_at: string | null;
}

export type TimelineItem = TimelineComment | TimelineEvent;

export interface TransitionOption {
    id: number;
    name: string;
    to_status_id: number;
    to_status: StatusSummary | null;
    requires_comment: boolean;
    requires_assignee: boolean;
}

export interface QueueSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    team: { id: number; name: string } | null;
    filters: Record<string, unknown>;
    sort_by: string;
    sort_direction: 'asc' | 'desc';
    columns: string[];
    ticket_count?: number;
}

export interface TicketOptions {
    statuses: StatusSummary[];
    priorities: PrioritySummary[];
    labels: LabelSummary[];
    teams: { id: number; name: string }[];
    sources: string[];
    assignees?: UserSummary[];
    requesters?: (UserSummary & { organization_id: number | null })[];
}
