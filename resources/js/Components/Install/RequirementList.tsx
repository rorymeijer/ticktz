import { IconAlert, IconCheckCircle } from '@/Components/Icons';
import { cn } from '@/lib/cn';

export interface Requirement {
    key: string;
    label: string;
    ok: boolean;
    detail: string | null;
}

/**
 * The requirements table.
 *
 * Failures are listed first. On a screen whose only purpose is telling you
 * what is wrong, making somebody scroll past twenty green rows to find the one
 * red one is a design that works against itself.
 */
export function RequirementList({ items, tone = 'required' }: { items: Requirement[]; tone?: 'required' | 'recommended' }) {
    const sorted = [...items].sort((a, b) => Number(a.ok) - Number(b.ok));

    return (
        <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
            {sorted.map((item) => (
                <li key={item.key} className="flex items-start gap-2.5 px-3 py-2">
                    {item.ok ? (
                        <IconCheckCircle className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                    ) : (
                        <IconAlert
                            className={cn(
                                'mt-0.5 h-4 w-4 shrink-0',
                                tone === 'required' ? 'text-red-600' : 'text-amber-600',
                            )}
                            aria-hidden="true"
                        />
                    )}
                    <span className="min-w-0 flex-1">
                        <span
                            className={cn(
                                'block text-sm',
                                item.ok ? 'text-slate-700' : tone === 'required' ? 'font-medium text-red-700' : 'text-amber-700',
                            )}
                        >
                            {item.label}
                        </span>
                        {item.detail ? <span className="block text-xs text-slate-600">{item.detail}</span> : null}
                    </span>
                </li>
            ))}
        </ul>
    );
}
