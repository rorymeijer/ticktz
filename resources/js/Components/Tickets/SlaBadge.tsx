import { useEffect, useState } from 'react';

import { Badge, type BadgeTone } from '@/Components/UI';
import { cn } from '@/lib/cn';
import { useTranslations } from '@/hooks/useTranslations';
import type { SlaTimerSummary } from '@/types/tickets';

/**
 * The countdown an agent works to.
 *
 * The remaining time arrives from the server already measured against the
 * business calendar — the browser has no idea when the desk is shut, and a
 * countdown that ticks through the night would be a lie. What ticks here is
 * only the *wall-clock* approach to the due date, recomputed once a minute so
 * a tab left open does not show a stale figure; when the calendar is closed
 * the server's figure is what shows.
 */

type Tone = BadgeTone;

/**
 * Minutes as something readable: "45 min", "3 h 20 min", "2 d 4 h".
 */
export function formatDuration(
    minutes: number,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    const total = Math.max(0, Math.round(minutes));

    if (total < 60) {
        return t('sla.duration.minutes', { count: total });
    }

    if (total < 60 * 24) {
        const hours = Math.floor(total / 60);
        const rest = total % 60;

        return rest === 0
            ? t('sla.duration.hours', { count: hours })
            : t('sla.duration.hours_minutes', { hours, minutes: rest });
    }

    const days = Math.floor(total / (60 * 24));
    const hours = Math.floor((total % (60 * 24)) / 60);

    return hours === 0 ? t('sla.duration.days', { count: days }) : t('sla.duration.days_hours', { days, hours });
}

/**
 * Colour by how much trouble the ticket is in, not by status name: an agent
 * scanning a list reads the colour first and the words second.
 */
function toneFor(timer: SlaTimerSummary, remaining: number | null): Tone {
    if (timer.status === 'breached' || timer.breached_at !== null) return 'red';
    if (timer.status === 'met') return 'green';
    if (timer.status === 'cancelled') return 'slate';
    if (timer.status === 'paused') return 'blue';
    if (remaining !== null && remaining <= 0) return 'red';
    if (remaining !== null && remaining <= 60) return 'amber';

    return 'slate';
}

/**
 * How many minutes are left, allowing for the wall clock having moved on since
 * the page was rendered.
 *
 * Only ever *reduces* the server's figure: time passing cannot give a ticket
 * more of its target back, but a closed calendar means it may consume less
 * than the wall clock suggests. Taking the smaller of the two is honest in
 * both directions.
 */
function liveRemaining(timer: SlaTimerSummary, now: number): number | null {
    if (timer.minutes_remaining === null) return null;
    if (timer.status === 'paused') return timer.minutes_remaining;

    const wallClock = Math.round((new Date(timer.due_at).getTime() - now) / 60000);

    return Math.min(timer.minutes_remaining, wallClock);
}

export function SlaBadge({
    timer,
    showMetric = false,
    compact = false,
    className,
}: {
    timer: SlaTimerSummary | null;
    showMetric?: boolean;
    /** Terse text for a table cell, where "Paused — clock stopped" does not fit. */
    compact?: boolean;
    className?: string;
}) {
    const { t } = useTranslations();
    const [now, setNow] = useState(() => Date.now());

    // A ticket list left open on a wall display should not go stale. Once a
    // minute is as often as the text can change.
    useEffect(() => {
        if (!timer || timer.status === 'paused' || timer.completed_at) {
            return;
        }

        const id = window.setInterval(() => setNow(Date.now()), 60_000);

        return () => window.clearInterval(id);
    }, [timer]);

    if (!timer) {
        return null;
    }

    const remaining = liveRemaining(timer, now);
    const metric = t(`sla.metrics.${timer.metric}`);

    let label: string;

    if (timer.status === 'paused') {
        label = compact ? t('sla.status.paused') : t('sla.badge.paused');
    } else if (timer.status === 'met') {
        label = t('sla.status.met');
    } else if (timer.status === 'cancelled') {
        label = t('sla.status.cancelled');
    } else if (remaining === null) {
        label = t(`sla.status.${timer.status}`);
    } else if (remaining <= 0) {
        const time = formatDuration(Math.abs(remaining), t);

        label = compact ? t('sla.badge.overdue_short', { time }) : t('sla.badge.overdue_by', { time });
    } else {
        const time = formatDuration(remaining, t);

        label = compact ? time : t('sla.badge.due_in', { time });
    }

    return (
        <Badge tone={toneFor(timer, remaining)} className={cn('whitespace-nowrap', className)}>
            <span className="sr-only">{t('sla.badge.aria', { metric, state: label })}</span>
            <span aria-hidden="true">
                {showMetric ? `${metric}: ` : ''}
                {label}
            </span>
        </Badge>
    );
}

/**
 * The bar that shows how much of a target has gone. Separate from the badge
 * because the ticket detail has room for it and a list row does not.
 */
export function SlaProgress({ timer }: { timer: SlaTimerSummary }) {
    const { t } = useTranslations();
    const percent = Math.min(100, Math.max(0, timer.percent_elapsed));
    const breached = timer.breached_at !== null || timer.status === 'breached';

    const colour = breached
        ? 'bg-red-500'
        : timer.status === 'met'
          ? 'bg-emerald-500'
          : percent >= 75
            ? 'bg-amber-500'
            : 'bg-brand-500';

    return (
        <div
            className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100"
            role="progressbar"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={t(`sla.metrics.${timer.metric}`)}
        >
            <div className={`h-full rounded-full transition-all ${colour}`} style={{ width: `${percent}%` }} />
        </div>
    );
}
