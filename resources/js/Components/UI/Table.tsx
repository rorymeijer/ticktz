import type { ReactNode, ThHTMLAttributes, TdHTMLAttributes } from 'react';

import { cn } from '@/lib/cn';

export function Table({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div className="overflow-x-auto">
            <table className={cn('min-w-full divide-y divide-slate-200 text-left text-sm', className)}>{children}</table>
        </div>
    );
}

export function THead({ children }: { children: ReactNode }) {
    return <thead className="bg-slate-50">{children}</thead>;
}

export function TBody({ children }: { children: ReactNode }) {
    return <tbody className="divide-y divide-slate-100 bg-white">{children}</tbody>;
}

export function TR({ children, className }: { children: ReactNode; className?: string }) {
    return <tr className={cn('hover:bg-slate-50/70', className)}>{children}</tr>;
}

export function TH({ children, className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            scope="col"
            className={cn('whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500', className)}
            {...props}
        >
            {children}
        </th>
    );
}

export function TD({ children, className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return (
        <td className={cn('px-4 py-2.5 align-middle text-slate-700', className)} {...props}>
            {children}
        </td>
    );
}
