import type { ReactNode } from 'react';

import { cn } from '@/lib/cn';

export function Card({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <div className={cn('rounded-xl border border-slate-200 bg-white shadow-card', className)}>{children}</div>
    );
}

export function CardHeader({
    title,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4', className)}>
            <div className="min-w-0">
                <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
                {description ? <p className="mt-0.5 text-xs text-slate-500">{description}</p> : null}
            </div>
            {actions ? <div className="flex shrink-0 items-center gap-2">{actions}</div> : null}
        </div>
    );
}

export function CardBody({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('px-5 py-4', className)}>{children}</div>;
}

export function CardFooter({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <div className={cn('flex items-center justify-end gap-2 border-t border-slate-200 bg-slate-50/60 px-5 py-3', className)}>
            {children}
        </div>
    );
}
