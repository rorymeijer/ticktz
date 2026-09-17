import type {
    InputHTMLAttributes,
    LabelHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
    TextareaHTMLAttributes,
} from 'react';
import { forwardRef, useId } from 'react';

import { cn } from '@/lib/cn';

const controlClasses =
    'block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm ' +
    'placeholder:text-slate-500 focus:border-brand-500 focus:ring-brand-500 ' +
    'disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500';

export function Label({
    required,
    className,
    children,
    ...props
}: LabelHTMLAttributes<HTMLLabelElement> & { required?: boolean }) {
    return (
        <label className={cn('block text-sm font-medium text-slate-700', className)} {...props}>
            {children}
            {required ? (
                <span className="ml-0.5 text-red-600" aria-hidden="true">
                    *
                </span>
            ) : null}
        </label>
    );
}

export function InputError({ message, className }: { message?: string | null; className?: string }) {
    if (!message) return null;

    return (
        <p className={cn('mt-1 text-xs font-medium text-red-600', className)} role="alert">
            {message}
        </p>
    );
}

export function HelpText({ children, className, id }: { children: ReactNode; className?: string; id?: string }) {
    return (
        <p id={id} className={cn('mt-1 text-xs text-slate-500', className)}>
            {children}
        </p>
    );
}

export const TextInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(function TextInput(
    { className, ...props },
    ref,
) {
    return <input ref={ref} className={cn(controlClasses, className)} {...props} />;
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea(
    { className, rows = 4, ...props },
    ref,
) {
    return <textarea ref={ref} rows={rows} className={cn(controlClasses, className)} {...props} />;
});

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(function Select(
    { className, children, ...props },
    ref,
) {
    return (
        <select ref={ref} className={cn(controlClasses, 'pr-9', className)} {...props}>
            {children}
        </select>
    );
});

export function Checkbox({ className, ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            type="checkbox"
            className={cn(
                'h-4 w-4 rounded border-slate-300 text-brand-600 shadow-sm focus:ring-brand-500',
                className,
            )}
            {...props}
        />
    );
}

/**
 * Label + control + error, wired together with a generated id so every field
 * in the app is announced correctly by screen readers.
 */
export function Field({
    label,
    error,
    help,
    required,
    className,
    children,
}: {
    label?: ReactNode;
    error?: string | null;
    help?: ReactNode;
    required?: boolean;
    className?: string;
    children: (props: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string }) => ReactNode;
}) {
    const id = useId();
    const describedBy = error ? `${id}-error` : help ? `${id}-help` : undefined;

    return (
        <div className={className}>
            {label ? (
                <Label htmlFor={id} required={required}>
                    {label}
                </Label>
            ) : null}
            <div className={label ? 'mt-1.5' : undefined}>
                {children({ id, 'aria-invalid': error ? true : undefined, 'aria-describedby': describedBy })}
            </div>
            {help && !error ? (
                <HelpText className="mt-1" id={`${id}-help`}>
                    {help}
                </HelpText>
            ) : null}
            {error ? (
                <p id={`${id}-error`} role="alert" className="mt-1 text-xs font-medium text-red-600">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
