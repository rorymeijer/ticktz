import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

export type BadgeTone = 'slate' | 'brand' | 'green' | 'amber' | 'red' | 'blue' | 'purple';

const tones: Record<BadgeTone, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
    blue: 'bg-sky-50 text-sky-700 ring-sky-200',
    purple: 'bg-violet-50 text-violet-700 ring-violet-200',
};

export function Badge({
    tone = 'slate',
    className,
    children,
    dotColor,
    title,
}: {
    tone?: BadgeTone;
    className?: string;
    children: ReactNode;
    dotColor?: string;
    /** What this badge is, for a name that could be read as something else. */
    title?: string;
}) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                tones[tone],
                className,
            )}
        >
            {dotColor ? (
                <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: dotColor }} />
            ) : null}
            {children}
        </span>
    );
}
