import { Badge } from '@/Components/UI';
import { tintedChip } from '@/lib/contrast';
import type { PrioritySummary, StatusSummary } from '@/types/tickets';

/**
 * Status and priority are rendered with the colour the administrator chose, so
 * the chips stay meaningful after someone renames "In progress" to "Onderhanden".
 */
export function StatusBadge({ status }: { status: StatusSummary | null }) {
    if (!status) return null;

    return (
        <Badge tone="slate" dotColor={status.color}>
            {status.name}
        </Badge>
    );
}

export function PriorityBadge({ priority }: { priority: PrioritySummary | null }) {
    if (!priority) return null;

    return (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-700">
            <span
                aria-hidden="true"
                className="h-2 w-2 rounded-full"
                style={{ backgroundColor: priority.color }}
            />
            {priority.name}
        </span>
    );
}

export function LabelChip({ label }: { label: { name: string; color: string } }) {
    // The ink is derived rather than taken from the label: a label's own colour
    // on a 10% tint of itself measures as little as 2.85:1, and the colour is
    // the administrator's to choose, so no fixed palette can fix it.
    return (
        <span
            className="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium"
            style={tintedChip(label.color)}
        >
            {label.name}
        </span>
    );
}
