import { Checkbox, Field, Select, TextInput, Textarea } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { RichTextField } from '@/Components/RichText/RichTextField';

export interface FieldDefinition {
    id: number;
    key: string;
    label: string;
    type:
        | 'text'
        | 'textarea'
        | 'number'
        | 'date'
        | 'datetime'
        | 'select'
        | 'multiselect'
        | 'checkbox'
        | 'email'
        | 'url'
        | 'user';
    options: { value: string; label: string }[];
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    validation: { min?: number; max?: number; regex?: string };
}

export type FieldValue = string | number | boolean | string[] | null;

/**
 * Renders one custom field.
 *
 * The form is defined by data, so this component is the only place that knows
 * how each field type looks — the portal form, the agent form and (later) the
 * asset form all render through here.
 */
export function DynamicField({
    field,
    value,
    error,
    onChange,
}: {
    field: FieldDefinition;
    value: FieldValue;
    error?: string;
    onChange: (value: FieldValue) => void;
}) {
    const { t } = useTranslations();

    if (field.type === 'checkbox') {
        return (
            <div>
                <label className="flex items-start gap-2.5">
                    <Checkbox
                        checked={Boolean(value)}
                        onChange={(event) => onChange(event.target.checked)}
                        className="mt-0.5"
                    />
                    <span>
                        <span className="block text-sm font-medium text-slate-700">
                            {field.label}
                            {field.is_required ? (
                                <span aria-hidden="true" className="ml-0.5 text-red-600">
                                    *
                                </span>
                            ) : null}
                        </span>
                        {field.help_text ? (
                            <span className="mt-0.5 block text-xs text-slate-500">{field.help_text}</span>
                        ) : null}
                    </span>
                </label>
                {error ? (
                    <p role="alert" className="mt-1 text-xs font-medium text-red-600">
                        {error}
                    </p>
                ) : null}
            </div>
        );
    }

    /*
     * Handled before the Field wrapper rather than inside it: the editor is a
     * contenteditable, and `<label for>` binds only to real form controls, so
     * it needs the label to carry an id and the field an aria-labelledby.
     * RichTextField is that same wrapper, done that way.
     */
    if (field.type === 'textarea') {
        return (
            <RichTextField
                label={field.label}
                help={field.help_text}
                error={error}
                required={field.is_required}
                value={String(value ?? '')}
                onChange={onChange}
                placeholder={field.placeholder ?? undefined}
                minHeight="7rem"
            />
        );
    }

    return (
        <Field label={field.label} help={field.help_text} error={error} required={field.is_required}>
            {(props) => {
                switch (field.type) {
                    case 'select':
                        return (
                            <Select
                                {...props}
                                value={String(value ?? '')}
                                required={field.is_required}
                                onChange={(event) => onChange(event.target.value || null)}
                            >
                                <option value="">{t('portal.form.select_placeholder')}</option>
                                {field.options.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        );

                    case 'multiselect': {
                        const selected = Array.isArray(value) ? value : [];

                        return (
                            <div className="space-y-1.5 rounded-lg border border-slate-200 bg-white p-2.5">
                                {field.options.map((option) => (
                                    <label key={option.value} className="flex cursor-pointer items-center gap-2">
                                        <Checkbox
                                            checked={selected.includes(option.value)}
                                            onChange={() =>
                                                onChange(
                                                    selected.includes(option.value)
                                                        ? selected.filter((item) => item !== option.value)
                                                        : [...selected, option.value],
                                                )
                                            }
                                        />
                                        <span className="text-sm text-slate-700">{option.label}</span>
                                    </label>
                                ))}
                            </div>
                        );
                    }

                    case 'number':
                        return (
                            <TextInput
                                {...props}
                                type="number"
                                value={value === null || value === undefined ? '' : String(value)}
                                required={field.is_required}
                                min={field.validation.min}
                                max={field.validation.max}
                                placeholder={field.placeholder ?? undefined}
                                onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}
                            />
                        );

                    default:
                        return (
                            <TextInput
                                {...props}
                                type={
                                    field.type === 'date'
                                        ? 'date'
                                        : field.type === 'datetime'
                                          ? 'datetime-local'
                                          : field.type === 'email'
                                            ? 'email'
                                            : field.type === 'url'
                                              ? 'url'
                                              : 'text'
                                }
                                value={String(value ?? '')}
                                required={field.is_required}
                                placeholder={field.placeholder ?? undefined}
                                onChange={(event) => onChange(event.target.value)}
                            />
                        );
                }
            }}
        </Field>
    );
}
