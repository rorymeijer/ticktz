import type { ReactNode } from 'react';

export function EmptyState({
    title,
    description,
    action,
    icon,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    icon?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
            {icon ? <div className="mb-1 text-slate-300">{icon}</div> : null}
            <p className="text-sm font-semibold text-slate-900">{title}</p>
            {description ? <p className="max-w-sm text-sm text-slate-500">{description}</p> : null}
            {action ? <div className="mt-3">{action}</div> : null}
        </div>
    );
}
