import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

export interface CheckboxOption<T extends string | number> {
    value: T;
    label: string;
    description?: ReactNode;
}

/**
 * Multi-select rendered as a scrollable list of checkboxes. Preferred over a
 * native multi-select: it works on touch, shows descriptions, and never hides
 * a selection behind a scrollbar the user has to discover.
 */
export function CheckboxGroup<T extends string | number>({
    options,
    selected,
    onChange,
    columns = 1,
    maxHeight = 'max-h-72',
    emptyLabel,
}: {
    options: CheckboxOption<T>[];
    selected: T[];
    onChange: (values: T[]) => void;
    columns?: 1 | 2;
    maxHeight?: string;
    emptyLabel?: string;
}) {
    const toggle = (value: T) => {
        onChange(selected.includes(value) ? selected.filter((item) => item !== value) : [...selected, value]);
    };

    if (options.length === 0) {
        return <p className="text-sm text-slate-500">{emptyLabel}</p>;
    }

    return (
        <div
            className={cn(
                'grid gap-1.5 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2.5',
                maxHeight,
                columns === 2 && 'sm:grid-cols-2',
            )}
        >
            {options.map((option) => (
                <label
                    key={String(option.value)}
                    className="flex cursor-pointer items-start gap-2 rounded-md px-1.5 py-1 hover:bg-slate-50"
                >
                    <input
                        type="checkbox"
                        checked={selected.includes(option.value)}
                        onChange={() => toggle(option.value)}
                        className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span className="min-w-0">
                        <span className="block text-sm text-slate-700">{option.label}</span>
                        {option.description ? (
                            <span className="block text-xs text-slate-500">{option.description}</span>
                        ) : null}
                    </span>
                </label>
            ))}
        </div>
    );
}
