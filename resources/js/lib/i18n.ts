/**
 * Minimal client-side translator that understands the subset of Laravel's
 * message format the UI actually uses: `:placeholder` replacement and
 * `singular|plural` pluralisation.
 *
 * Deliberately hand-rolled — pulling in a full i18n runtime would add more
 * weight than the whole feature is worth, and the dictionary already arrives
 * pre-resolved for the active locale as an Inertia shared prop.
 */

export type Replacements = Record<string, string | number>;

export function translate(
    dictionary: Record<string, string>,
    key: string,
    replacements: Replacements = {},
): string {
    const line = dictionary[key];

    if (line === undefined) {
        // Falling back to the key makes missing translations obvious in the UI
        // without breaking the page.
        return key;
    }

    return applyReplacements(choose(line, replacements.count), replacements);
}

function choose(line: string, count: unknown): string {
    if (typeof count !== 'number' || !line.includes('|')) {
        return line;
    }

    const [singular, plural] = line.split('|');

    return count === 1 ? singular : (plural ?? singular);
}

function applyReplacements(line: string, replacements: Replacements): string {
    return Object.entries(replacements).reduce((carry, [token, value]) => {
        const replacement = String(value);

        return carry
            .replaceAll(`:${token.toUpperCase()}`, replacement.toUpperCase())
            .replaceAll(
                `:${token.charAt(0).toUpperCase()}${token.slice(1)}`,
                replacement.charAt(0).toUpperCase() + replacement.slice(1),
            )
            .replaceAll(`:${token}`, replacement);
    }, line);
}
