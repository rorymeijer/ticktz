import { cn } from '@/lib/cn';

/**
 * Accessible on/off switch. Renders a real checkbox behind the visuals so
 * keyboard and screen-reader behaviour comes for free.
 */
export function Toggle({
    checked,
    onChange,
    label,
    description,
    disabled,
    name,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    description?: string;
    disabled?: boolean;
    name?: string;
}) {
    return (
        <label className={cn('flex cursor-pointer items-start gap-3', disabled && 'cursor-not-allowed opacity-60')}>
            <span className="relative mt-0.5 inline-flex h-5 w-9 shrink-0 items-center">
                <input
                    type="checkbox"
                    name={name}
                    checked={checked}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.checked)}
                    className="peer sr-only"
                />
                <span
                    aria-hidden="true"
                    className={cn(
                        'h-5 w-9 rounded-full transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500 peer-focus-visible:ring-offset-2',
                        checked ? 'bg-brand-600' : 'bg-slate-300',
                    )}
                />
                <span
                    aria-hidden="true"
                    className={cn(
                        'absolute left-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform',
                        checked && 'translate-x-4',
                    )}
                />
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-medium text-slate-700">{label}</span>
                {description ? <span className="mt-0.5 block text-xs text-slate-500">{description}</span> : null}
            </span>
        </label>
    );
}
