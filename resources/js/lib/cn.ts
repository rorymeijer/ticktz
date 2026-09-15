/**
 * Tiny conditional class-name joiner. Avoids pulling in clsx/tailwind-merge:
 * the component library below never needs class *merging*, only joining.
 */
export type ClassValue = string | number | null | undefined | false | ClassValue[] | Record<string, boolean | undefined>;

export function cn(...values: ClassValue[]): string {
    const out: string[] = [];

    for (const value of values) {
        if (!value) continue;

        if (typeof value === 'string' || typeof value === 'number') {
            out.push(String(value));
        } else if (Array.isArray(value)) {
            const nested = cn(...value);
            if (nested) out.push(nested);
        } else {
            for (const [key, enabled] of Object.entries(value)) {
                if (enabled) out.push(key);
            }
        }
    }

    return out.join(' ');
}
