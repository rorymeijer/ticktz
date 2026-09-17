import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

import { translate, type Replacements } from '@/lib/i18n';
import type { SharedProps } from '@/types';

/**
 * `const { t, locale } = useTranslations()` — the single way UI strings are
 * resolved in Ticktz. Never hard-code user-facing copy in a component.
 */
export function useTranslations() {
    const { translations, locale, locales } = usePage<SharedProps>().props;

    const t = useCallback(
        (key: string, replacements: Replacements = {}) => translate(translations, key, replacements),
        [translations],
    );

    return useMemo(() => ({ t, locale, locales }), [t, locale, locales]);
}
