import { useId, useMemo, useState } from 'react';

import { AXIS_TEXT, GRID, axisDate, axisTicks } from '@/Components/Charts/tokens';
import { useTranslations } from '@/hooks/useTranslations';

export interface LineSeries {
    key: string;
    label: string;
    color: string;
    /** Null is "nothing measured that day" — a gap, not a zero. */
    values: (number | null)[];
}

/**
 * A line over time, one to two series, one y-axis.
 *
 * **Never two y-axes.** Two measures on different scales get two charts — the
 * alignment of a second scale is arbitrary, so the chart invents a correlation
 * that is not in the data. That is why `first response` and `resolution` are
 * drawn as two of these rather than one with two axes.
 *
 * Hand-drawn SVG rather than a charting library: the whole of this is a path
 * and some text, and a dependency that renders it would be larger than the
 * rest of the front end put together.
 */
export function LineChart({
    labels,
    series,
    format = (value: number) => String(value),
    height = 180,
}: {
    labels: string[];
    series: LineSeries[];
    format?: (value: number) => string;
    height?: number;
}) {
    const { t, locale } = useTranslations();
    const clipId = useId();
    const [hover, setHover] = useState<number | null>(null);

    const width = 640;

    const { max, ticks } = useMemo(() => {
        const values = series.flatMap((line) => line.values.filter((value): value is number => value !== null));

        return axisTicks(Math.max(1, ...values));
    }, [series]);

    // The x-axis band lives inside the viewBox, so the card never grows a tiny
    // nested scrollbar to reach labels the plot pushed out — and the left
    // gutter is sized to the widest label the formatter actually produces. A
    // fixed 44px fits "120" and clips "2h 24m", which is exactly the case the
    // duration charts hit.
    const padding = useMemo(() => {
        const longest = Math.max(...ticks.map((tick) => format(tick).length));

        return { top: 12, right: 12, bottom: 24, left: Math.max(longest * 6.5 + 12, 32) };
    }, [format, ticks]);

    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;


    const x = (index: number) =>
        padding.left + (labels.length <= 1 ? plotWidth / 2 : (index / (labels.length - 1)) * plotWidth);
    const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight;

    const everyNth = Math.max(1, Math.ceil(labels.length / 8));

    if (labels.length === 0) {
        return <p className="py-10 text-center text-sm text-slate-500">{t('reports.no_data')}</p>;
    }

    return (
        <div className="relative">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="w-full"
                role="img"
                aria-label={series.map((line) => line.label).join(', ')}
                onMouseLeave={() => setHover(null)}
            >
                <defs>
                    <clipPath id={clipId}>
                        <rect x={padding.left} y={padding.top} width={plotWidth} height={plotHeight} />
                    </clipPath>
                </defs>

                {ticks.map((tick) => (
                    <g key={tick}>
                        {/* Solid hairlines. Dashing reads as "projection" when
                            it is only a grid. */}
                        <line
                            x1={padding.left}
                            x2={width - padding.right}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke={GRID}
                            strokeWidth={1}
                        />
                        <text x={padding.left - 8} y={y(tick) + 3} textAnchor="end" fontSize={10} fill={AXIS_TEXT}>
                            {format(tick)}
                        </text>
                    </g>
                ))}

                {labels.map((label, index) =>
                    index % everyNth === 0 ? (
                        <text
                            key={label}
                            x={x(index)}
                            y={height - 6}
                            textAnchor="middle"
                            fontSize={10}
                            fill={AXIS_TEXT}
                        >
                            {axisDate(label, locale)}
                        </text>
                    ) : null,
                )}

                <g clipPath={`url(#${clipId})`}>
                    {series.map((line) => (
                        <path
                            key={line.key}
                            d={pathFor(line.values, x, y)}
                            fill="none"
                            stroke={line.color}
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                    ))}

                    {/* A day with a value and no measured neighbour draws no
                        line segment at all — a period with one measurement in
                        it would otherwise render as an empty chart. */}
                    {series.map((line) =>
                        line.values.map((value, index) =>
                            value !== null && isIsolated(line.values, index) ? (
                                <circle
                                    key={`${line.key}-${index}`}
                                    cx={x(index)}
                                    cy={y(value)}
                                    r={3}
                                    fill={line.color}
                                />
                            ) : null,
                        ),
                    )}
                </g>

                {hover !== null ? (
                    <g>
                        <line
                            x1={x(hover)}
                            x2={x(hover)}
                            y1={padding.top}
                            y2={padding.top + plotHeight}
                            stroke={GRID}
                            strokeWidth={1}
                        />
                        {series.map((line) => {
                            const value = line.values[hover];

                            return value === null || value === undefined ? null : (
                                <circle
                                    key={line.key}
                                    cx={x(hover)}
                                    cy={y(value)}
                                    r={4}
                                    fill={line.color}
                                    /* A surface ring, not a border: it
                                       separates overlapping marks without
                                       drawing a box around them. */
                                    stroke="#ffffff"
                                    strokeWidth={2}
                                />
                            );
                        })}
                    </g>
                ) : null}

                {/* Hit targets wider than the marks, so a value is easy to
                    land on with a mouse. */}
                {labels.map((label, index) => (
                    <rect
                        key={`hit-${label}`}
                        x={x(index) - plotWidth / Math.max(labels.length, 1) / 2}
                        y={padding.top}
                        width={Math.max(plotWidth / Math.max(labels.length, 1), 6)}
                        height={plotHeight}
                        fill="transparent"
                        onMouseEnter={() => setHover(index)}
                    />
                ))}
            </svg>

            {hover !== null ? (
                <div
                    className="pointer-events-none absolute -translate-x-1/2 rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs text-white shadow-popover"
                    style={{ left: `${(x(hover) / width) * 100}%`, top: 0 }}
                >
                    <p className="font-medium">{axisDate(labels[hover], locale)}</p>
                    {series.map((line) => (
                        <p key={line.key} className="mt-0.5 flex items-center gap-1.5 text-slate-200">
                            <span
                                aria-hidden="true"
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ backgroundColor: line.color }}
                            />
                            {line.label}:{' '}
                            <span className="font-medium text-white">
                                {line.values[hover] === null || line.values[hover] === undefined
                                    ? '—'
                                    : format(line.values[hover] as number)}
                            </span>
                        </p>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

/**
 * A path that breaks at gaps rather than drawing through them — a day with
 * nothing measured is not a day with a value of zero.
 */
function pathFor(values: (number | null)[], x: (index: number) => number, y: (value: number) => number): string {
    let path = '';
    let open = false;

    values.forEach((value, index) => {
        if (value === null || value === undefined) {
            open = false;

            return;
        }

        path += `${open ? 'L' : 'M'}${x(index).toFixed(1)},${y(value).toFixed(1)} `;
        open = true;
    });

    return path.trim();
}

/**
 * Whether a point has no measured neighbour on either side, and so would be
 * drawn by no line segment.
 */
function isIsolated(values: (number | null)[], index: number): boolean {
    const before = index > 0 ? values[index - 1] : null;
    const after = index < values.length - 1 ? values[index + 1] : null;

    return (before === null || before === undefined) && (after === null || after === undefined);
}
