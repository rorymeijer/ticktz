import type { Config as ZiggyConfig } from 'ziggy-js';

export interface LocaleOption {
    code: string;
    name: string;
    native: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    locale: string;
    initials: string;
    avatar_color: string;
    is_admin: boolean;
    is_agent: boolean;
    is_requester: boolean;
    roles: string[];
    permissions: string[];
    team_ids: number[];
    organization_id: number | null;
    email_verified_at?: string | null;
}

export interface Flash {
    success?: string | null;
    error?: string | null;
    info?: string | null;
}

export interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta?: Record<string, unknown>;
    current_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    last_page: number;
}

export type SharedProps = {
    app: { name: string; version: string };
    auth: { user: User | null };
    locale: string;
    locales: LocaleOption[];
    translations: Record<string, string>;
    flash: Flash;
    /** Approvals waiting on the signed-in user, for the nav badge. */
    approvals_waiting: number;
    /** Which page components have a manual chapter, so the `?` can decide whether to exist. */
    help: { pages: string[] };
    ziggy: ZiggyConfig & { location: string };
    errors: Record<string, string>;
};

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & SharedProps;
