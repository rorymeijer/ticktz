/**
 * Shapes the reporting screen receives. These mirror `ReportBuilder::build()`
 * — when one changes, change the other.
 *
 * The four reports share one envelope rather than having four types, because
 * the screen switches between them on one prop; the optional keys are which
 * report is in play.
 */

export type ReportName = 'sla' | 'volume' | 'workload' | 'cycle_time';

export interface ReportFilters {
    report: ReportName;
    from: string;
    to: string;
    dimension: string;
}

export interface ReportOptions {
    reports: ReportName[];
    dimensions: string[];
}

export interface SavedReport {
    id: number;
    name: string;
    description: string | null;
    report: ReportName;
    filters: Record<string, string>;
    is_shared: boolean;
    is_mine: boolean;
    created_at: string | null;
}

interface SlaDay {
    date: string;
    met: number;
    breached: number;
}

interface VolumeDay {
    date: string;
    created: number;
    resolved: number;
    reopened: number;
}

interface CycleDay {
    date: string;
    first_response: number | null;
    resolution: number | null;
}

export interface ReportData {
    report: ReportName;
    from: string;
    to: string;
    dimension: string;

    /**
     * The SLA report keys its series by which clock; the others are a flat
     * list of days. Two fields rather than one union, because a union of an
     * object and an array is a type that reads as clever and checks as
     * nothing.
     */
    slaSeries?: { first_response: SlaDay[]; resolution: SlaDay[] };
    series?: (VolumeDay & CycleDay)[];

    summary?: {
        met?: number;
        breached?: number;
        compliance?: number | null;
        created?: number;
        resolved?: number;
        reopened?: number;
        clearance?: number | null;
        agents?: number;
        first_response?: number | null;
        resolution?: number | null;
        measured?: number;
    };

    by_metric?: Record<'first_response' | 'resolution', { met: number; breached: number; compliance: number | null }>;

    rows?: { id: number; label: string; resolved: number; average_seconds: number | null }[];

    breakdown?: {
        id: number;
        label: string;
        met?: number;
        breached?: number;
        total?: number;
        compliance?: number | null;
        count?: number;
        average_seconds?: number | null;
    }[];
}
