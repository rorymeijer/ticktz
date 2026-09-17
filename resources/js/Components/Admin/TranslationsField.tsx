import { Field, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * Per-locale overrides for a name that is stored as data rather than in the
 * language files — a status, a priority, a label, a portal category.
 *
 * The base value is always the fallback, so leaving a locale blank is a valid
 * answer rather than a missing translation.
 */
export function TranslationsField({
    label,
    values,
    onChange,
    baseLocale = 'en',
    multiline = false,
}: {
    label: string;
    values: Record<string, string>;
    onChange: (values: Record<string, string>) => void;
    baseLocale?: string;
    multiline?: boolean;
}) {
    const { locales } = useTranslations();
    const others = locales.filter((locale) => locale.code !== baseLocale);

    if (others.length === 0) {
        return null;
    }

    return (
        <Field label={label}>
            {() => (
                <div className="space-y-2">
                    {others.map((locale) => (
                        <label key={locale.code} className="flex items-center gap-2">
                            <span className="w-24 shrink-0 text-xs font-medium text-slate-600">{locale.native}</span>
                            <TextInput
                                value={values[locale.code] ?? ''}
                                aria-label={`${label} (${locale.native})`}
                                onChange={(event) => onChange({ ...values, [locale.code]: event.target.value })}
                            />
                        </label>
                    ))}
                    {multiline ? null : null}
                </div>
            )}
        </Field>
    );
}
