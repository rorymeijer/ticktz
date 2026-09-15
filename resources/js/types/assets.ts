/**
 * Shapes the asset screens receive from the server. These mirror
 * `Asset::toSummaryArray()` / `toDetailArray()` / `toPortalArray()` and
 * `AssetType::toSummaryArray()` / `toAdminArray()` — when one changes, change
 * the other.
 */

import type { StatusSummary, UserSummary } from '@/types/tickets';

export type AssetStatus = 'in_stock' | 'in_use' | 'in_repair' | 'retired' | 'disposed';

/**
 * Stored relation types plus the derived inverses — `hosts` is never written
 * to the database, it is what `installed_on` reads as from the other side.
 */
export type RelationType =
    | 'connected_to'
    | 'installed_on'
    | 'hosts'
    | 'part_of'
    | 'contains'
    | 'depends_on'
    | 'required_by'
    | 'backs_up'
    | 'backed_up_by';

export interface AssetTypeSummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon: string | null;
    color: string;
    tag_prefix: string | null;
    is_active: boolean;
    asset_count: number | null;
}

export interface AssetTypeAdmin extends AssetTypeSummary {
    name_translations: Record<string, string>;
    position: number;
    field_ids: number[];
    fields: { id: number; key: string; label: string }[];
}

export interface AssetSummary {
    id: number;
    asset_tag: string;
    name: string;
    status: AssetStatus;
    serial_number: string | null;
    manufacturer: string | null;
    model: string | null;
    location: string | null;
    type: { id: number; name: string; color: string } | null;
    assignee: UserSummary | null;
    organization: { id: number; name: string } | null;
    warranty_ends_at: string | null;
    warranty_expired: boolean;
    updated_at: string | null;
}

export interface AssetRelationView {
    id: number;
    type: RelationType;
    direction: 'in' | 'out';
    note: string | null;
    asset: AssetSummary;
}

export interface AssetDetail extends AssetSummary {
    team: { id: number; name: string } | null;
    purchased_at: string | null;
    purchase_cost: number | null;
    currency: string | null;
    notes: string | null;
    fields: { key: string; label: string; type: string; value: unknown; display: string }[];
    relations: AssetRelationView[];
    created_at: string | null;
}

/** Deliberately not `AssetSummary` minus keys — see `Asset::toPortalArray()`. */
export interface PortalAsset {
    id: number;
    asset_tag: string;
    name: string;
    status: AssetStatus;
    serial_number: string | null;
    manufacturer: string | null;
    model: string | null;
    location: string | null;
    type: { name: string; color: string } | null;
    warranty_ends_at: string | null;
    warranty_expired: boolean;
    is_mine: boolean;
    assignee: { name: string } | null;
}

export interface AssetTicket {
    key: string;
    subject: string;
    status: StatusSummary | null;
    requester: UserSummary | null;
    created_at: string | null;
}

export interface AssetOptions {
    types: AssetTypeSummary[];
    statuses: AssetStatus[];
    organizations: { id: number; name: string }[];
    teams: { id: number; name: string }[];
    relation_types?: RelationType[];
}

export interface ImportResult {
    stage: 'preview' | 'done';
    create?: number;
    update?: number;
    created?: number;
    updated?: number;
    errors: { line: number; message: string }[];
    preview?: {
        line: number;
        action: 'create' | 'update';
        asset_tag: string | null;
        name: string;
        type: string;
        status: string;
    }[];
}
