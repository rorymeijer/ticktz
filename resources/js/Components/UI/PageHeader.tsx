import type { ReactNode } from 'react';

export function PageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
}) {
    return (
        <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                <h2 className="text-xl font-semibold tracking-tight text-slate-900">{title}</h2>
                {description ? <p className="mt-1 max-w-3xl text-sm text-slate-500">{description}</p> : null}
            </div>
            {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div> : null}
        </div>
    );
}
