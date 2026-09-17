/**
 * Colour contrast, for the places where a colour is not ours to choose.
 *
 * Statuses, priorities, labels and asset types all carry a colour an
 * administrator picked. Using that colour directly as text — which is what a
 * tinted chip wants to do — produces whatever contrast the colour happens to
 * give: a mid-tone amber on its own 10% tint measures 2.85:1, well under the
 * 4.5:1 that small text needs.
 *
 * No fixed palette fixes this, because the palette is the administrator's. So
 * the ink is *derived*: keep the hue they chose, and darken it until it is
 * legible on the background it will actually sit on.
 */

/**
 * Where an unreadable colour lands: slate-700, which clears 4.5:1 against
 * every light background this application uses.
 */
const SAFE_INK = '#334155';

/** @return [r, g, b] in 0–255, or null when the string is not a hex colour. */
function parseHex(hex: string): [number, number, number] | null {
    const value = hex.trim().replace(/^#/, '');

    const full =
        value.length === 3
            ? value
                  .split('')
                  .map((c) => c + c)
                  .join('')
            : value;

    if (!/^[0-9a-f]{6}$/i.test(full)) return null;

    return [
        parseInt(full.slice(0, 2), 16),
        parseInt(full.slice(2, 4), 16),
        parseInt(full.slice(4, 6), 16),
    ];
}

function toHex([r, g, b]: [number, number, number]): string {
    return '#' + [r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('');
}

/** Relative luminance, per WCAG 2.1. */
export function luminance(rgb: [number, number, number]): number {
    const [r, g, b] = rgb.map((c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two colours, 1–21. */
export function contrastRatio(a: string, b: string): number {
    const ca = parseHex(a);
    const cb = parseHex(b);

    if (!ca || !cb) return 1;

    const la = luminance(ca);
    const lb = luminance(cb);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Composite a colour at `alpha` over an opaque backdrop. */
export function blend(color: string, backdrop: string, alpha: number): string {
    const c = parseHex(color);
    const b = parseHex(backdrop);

    if (!c || !b) return backdrop;

    return toHex([
        c[0] * alpha + b[0] * (1 - alpha),
        c[1] * alpha + b[1] * (1 - alpha),
        c[2] * alpha + b[2] * (1 - alpha),
    ] as [number, number, number]);
}

/**
 * A readable version of `color` against `background`, keeping its hue.
 *
 * Darkens toward black in small steps and returns the first shade that clears
 * `minimum`. Black always clears it against a light background, so this
 * terminates; a colour that already passes is returned untouched, which is the
 * common case for the darker half of any sensible palette.
 */
export function readableInk(color: string, background: string, minimum = 4.5): string {
    const rgb = parseHex(color);

    // A colour we cannot read is not a colour we can make legible. Returning
    // it unchanged would hand the caller whatever it was — and a chip that
    // paints white text on a white tint is invisible rather than merely
    // low-contrast. Fall back to ink that is readable on anything light.
    if (!rgb) return SAFE_INK;

    for (let factor = 1; factor >= 0; factor -= 0.02) {
        const shade = toHex([rgb[0] * factor, rgb[1] * factor, rgb[2] * factor] as [number, number, number]);

        if (contrastRatio(shade, background) >= minimum) return shade;
    }

    return '#000000';
}

/**
 * The pair a tinted chip needs: the label's colour at 10% over white, and ink
 * dark enough to read on it.
 */
export function tintedChip(color: string, backdrop = '#ffffff'): { backgroundColor: string; color: string } {
    const backgroundColor = blend(color, backdrop, 0.1);

    return { backgroundColor, color: readableInk(color, backgroundColor) };
}
