import type { ReactNode } from 'react';

import { AXIS_TEXT, GRID, STATUS, axisDate, axisTicks } from '@/Components/Charts/tokens';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';

/**
 * A number that is the whole story.
 *
 * Eight categorical hues when the answer is one figure is the most common way
 * a chart misses its point — so compliance, clearance and the averages are
 * tiles, not plots.
 */
export function StatTile({
    label,
    value,
    hint,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    tone?: 'default' | 'good' | 'bad';
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-card">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
            <p
                className={cn(
                    // A text token, not a series colour, and a sans face — a
                    // display or serif hero figure reads as decoration.
                    'mt-1 text-2xl font-semibold tabular-nums tracking-tight',
                    tone === 'good' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-700' : 'text-slate-900',
                )}
            >
                {value}
            </p>
            {hint ? <p className="mt-0.5 text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}

/**
 * The legend. Always present for two or more series, so identity is never
 * carried by colour alone.
 */
export function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-1.5 text-xs text-slate-600">
                    <span
                        aria-hidden="true"
                        className="h-2 w-2 shrink-0 rounded-full"
                        style={{ backgroundColor: item.color }}
                    />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

/**
 * Met against missed, day by day.
 *
 * Stacked because the pair is a whole — every finished clock is one or the
 * other — and in status colours because met and breached are a judgement, not
 * two identities. A 2px surface gap separates the segments rather than a
 * border drawn around them.
 */
export function StackedBars({
    days,
    height = 180,
}: {
    days: { date: string; met: number; breached: number }[];
    height?: number;
}) {
    const { t, locale } = useTranslations();

    const padding = { top: 12, right: 12, bottom: 24, left: 44 };
    const width = 640;
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;

    // Rounded up to something the gridlines divide into, so the axis reads
    // 0 / 3 / 6 rather than 0 / 3 / 5 — a midpoint label that is not the
    // midpoint is worse than no label at all.
    const { max, ticks } = axisTicks(Math.max(1, ...days.map((day) => day.met + day.breached)), 2);
    const step = plotWidth / Math.max(days.length, 1);
    const barWidth = Math.max(Math.min(step - 3, 26), 2);

    const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight;
    const everyNth = Math.max(1, Math.ceil(days.length / 8));

    if (days.length === 0) {
        return <p className="py-10 text-center text-sm text-slate-400">{t('reports.no_data')}</p>;
    }

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label={t('reports.sla.title')}>
            {ticks.map((tick) => (
                <g key={tick}>
                    <line
                        x1={padding.left}
                        x2={width - padding.right}
                        y1={y(tick)}
                        y2={y(tick)}
                        stroke={GRID}
                        strokeWidth={1}
                    />
                    <text x={padding.left - 8} y={y(tick) + 3} textAnchor="end" fontSize={10} fill={AXIS_TEXT}>
                        {tick}
                    </text>
                </g>
            ))}

            {days.map((day, index) => {
                const cx = padding.left + index * step + step / 2;
                const total = day.met + day.breached;

                if (total === 0) {
                    return null;
                }

                const breachedHeight = (day.breached / max) * plotHeight;
                const metHeight = (day.met / max) * plotHeight;
                // The surface gap, and only when both halves are present.
                const gap = day.met > 0 && day.breached > 0 ? 2 : 0;

                return (
                    <g key={day.date}>
                        <title>
                            {`${axisDate(day.date, locale)} · ${t('reports.sla.met')} ${day.met} · ${t('reports.sla.breached')} ${day.breached}`}
                        </title>
                        {day.met > 0 ? (
                            <rect
                                x={cx - barWidth / 2}
                                y={y(day.met)}
                                width={barWidth}
                                height={Math.max(metHeight - gap, 1)}
                                fill={STATUS.met}
                                rx={2}
                            />
                        ) : null}
                        {day.breached > 0 ? (
                            <rect
                                x={cx - barWidth / 2}
                                y={y(total)}
                                width={barWidth}
                                height={Math.max(breachedHeight, 1)}
                                fill={STATUS.breached}
                                rx={2}
                            />
                        ) : null}
                    </g>
                );
            })}

            {days.map((day, index) =>
                index % everyNth === 0 ? (
                    <text
                        key={`label-${day.date}`}
                        x={padding.left + index * step + step / 2}
                        y={height - 6}
                        textAnchor="middle"
                        fontSize={10}
                        fill={AXIS_TEXT}
                    >
                        {axisDate(day.date, locale)}
                    </text>
                ) : null,
            )}
        </svg>
    );
}

/**
 * Ranked rows with a bar behind the number.
 *
 * One series, so **one colour for every bar**. Shading each bar darker where
 * it is bigger would double-encode length as hue and burn the only free
 * channel on something the bar already says.
 */
export function BarList({
    rows,
    color,
    format = (value: number) => String(value),
    max: givenMax,
}: {
    rows: { id: number | string; label: string; value: number; meta?: string }[];
    color: string;
    format?: (value: number) => string;
    max?: number;
}) {
    const { t } = useTranslations();
    const max = givenMax ?? Math.max(1, ...rows.map((row) => row.value));

    if (rows.length === 0) {
        return <p className="py-8 text-center text-sm text-slate-400">{t('reports.no_data')}</p>;
    }

    return (
        <ul className="space-y-2">
            {rows.map((row) => (
                <li key={row.id}>
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="min-w-0 truncate text-sm text-slate-700">{row.label}</span>
                        <span className="shrink-0 text-sm font-medium tabular-nums text-slate-900">
                            {format(row.value)}
                        </span>
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                        <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                            <span
                                className="block h-full rounded-full"
                                style={{
                                    width: `${Math.max((row.value / max) * 100, 1)}%`,
                                    backgroundColor: color,
                                }}
                            />
                        </span>
                        {row.meta ? (
                            <span className="shrink-0 text-xs tabular-nums text-slate-400">{row.meta}</span>
                        ) : null}
                    </div>
                </li>
            ))}
        </ul>
    );
}
