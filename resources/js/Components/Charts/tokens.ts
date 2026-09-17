/**
 * Chart colours, and the reasoning behind each one.
 *
 * Every value here was run through the palette validator rather than chosen by
 * eye — CVD separation, lightness band, chroma floor and contrast against the
 * white card surface these charts sit on. The comments record what passed, so
 * a future change is re-validated rather than nudged.
 */

/**
 * Identity, not judgement. Assigned in fixed order and never cycled: a third
 * series folds into a stat tile or its own chart rather than growing this list,
 * and a series keeps its hue when a filter removes its neighbours.
 *
 * Validated on white: worst adjacent CVD ΔE 31.4, normal-vision ΔE 39.2, both
 * above 3:1 contrast.
 */
export const SERIES = ['#4f46e5', '#eb6834'] as const;

/**
 * Judgement, not identity. Only ever used where the colour *means* met or
 * missed — never as "series 3".
 *
 * Validated on white: CVD ΔE 8.6 (deutan), normal-vision ΔE 32.0, both above
 * 3:1. The legend carries a label beside every swatch, so the colour is never
 * the only thing saying which is which.
 */
export const STATUS = {
    met: '#059669',
    breached: '#dc2626',
} as const;

/** Hairline grid and axis, one shade off the surface. Solid — never dashed. */
export const GRID = '#e2e8f0';

export const AXIS_TEXT = '#94a3b8';

/**
 * Seconds as something a person reads: "2 d 4 h", "18 m".
 *
 * Two units at most. "2 d 4 h 17 m 3 s" is not a duration anybody compares.
 */
export function duration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return '—';

    const total = Math.max(Math.round(seconds), 0);

    if (total < 60) return `${total}s`;

    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (days > 0) return hours > 0 ? `${days}d ${hours}h` : `${days}d`;
    if (hours > 0) return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;

    return `${minutes}m`;
}

/** A date as `5 Sep`, for an axis that has to fit thirty of them. */
export function axisDate(iso: string, locale: string): string {
    return new Date(iso).toLocaleDateString(locale, { day: 'numeric', month: 'short' });
}

/**
 * A maximum the gridlines divide into cleanly, and the ticks to draw at it.
 *
 * An axis reading 2 / 2 / 1 / 1 / 0 is what you get from evenly spacing five
 * ticks across a maximum of two and rounding each — every second label is a
 * repeat, and the reader is left to work out which one their value sits
 * against. Small integer ranges get one tick per unit instead.
 */
export function axisTicks(max: number, preferred = 4): { max: number; ticks: number[] } {
    if (max <= preferred) {
        // One per unit: 0,1,2 rather than five ticks rounding into each other.
        const top = Math.max(Math.ceil(max), 1);

        return { max: top, ticks: Array.from({ length: top + 1 }, (_, index) => index) };
    }

    const magnitude = 10 ** Math.floor(Math.log10(max));
    const step = Math.ceil(max / preferred / (magnitude / 2)) * (magnitude / 2);
    const top = step * preferred;

    return { max: top, ticks: Array.from({ length: preferred + 1 }, (_, index) => index * step) };
}
