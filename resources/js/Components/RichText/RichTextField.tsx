import type { ReactNode } from 'react';
import { useId } from 'react';

import { HelpText, Label } from '@/Components/UI/Form';

import { RichTextEditor, type RichTextProfile } from './RichTextEditor';

/**
 * Label + editor + error, the rich text counterpart of {@link Field}.
 *
 * Its own component rather than a `Field` render prop for one reason: the
 * editor is a `contenteditable`, and `<label for>` only binds to real form
 * controls. Pointing a label at it would look right in the markup and announce
 * nothing, so the label gets an id and the editor an `aria-labelledby`.
 */
export function RichTextField({
    label,
    value,
    onChange,
    profile,
    error,
    help,
    required,
    placeholder,
    disabled,
    minHeight,
    className,
}: {
    label: string;
    value: string;
    onChange: (html: string) => void;
    profile?: RichTextProfile;
    error?: string | null;
    help?: ReactNode;
    required?: boolean;
    placeholder?: string;
    disabled?: boolean;
    minHeight?: string;
    className?: string;
}) {
    const id = useId();
    const describedBy = error ? `${id}-error` : help ? `${id}-help` : undefined;

    return (
        <div className={className}>
            {/*
             * No `htmlFor`: a contenteditable is not a labelable element, so the
             * binding is the editor's `aria-labelledby`. The click handler puts
             * back the one thing a real `for` would have given — clicking the
             * label focuses the field.
             */}
            <Label
                id={`${id}-label`}
                required={required}
                onClick={() => document.getElementById(id)?.focus()}
            >
                {label}
            </Label>
            <div className="mt-1.5">
                <RichTextEditor
                    id={id}
                    value={value}
                    onChange={onChange}
                    profile={profile}
                    labelledBy={`${id}-label`}
                    describedBy={describedBy}
                    invalid={Boolean(error)}
                    placeholder={placeholder}
                    disabled={disabled}
                    minHeight={minHeight}
                />
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
