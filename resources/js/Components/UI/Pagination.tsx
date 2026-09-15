import { Link } from '@inertiajs/react';

import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { Paginated } from '@/types';

/**
 * Renders Laravel's paginator links. Every list view in Ticktz is paginated —
 * unbounded queries are not allowed to reach the browser.
 */
export function Pagination<T>({ page, className }: { page: Paginated<T>; className?: string }) {
    const { t } = useTranslations();

    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            className={cn('flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3', className)}
            aria-label="Pagination"
        >
            <p className="text-xs text-slate-500">
                {t('common.pagination.showing', {
                    from: page.from ?? 0,
                    to: page.to ?? 0,
                    total: page.total,
                })}
            </p>
            <div className="flex flex-wrap items-center gap-1">
                {page.links.map((link, index) =>
                    link.url ? (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.url}
                            preserveScroll
                            preserveState
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(
                                'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                link.active
                                    ? 'bg-brand-600 text-white'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                            )}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <span
                            key={`${link.label}-${index}`}
                            className="rounded-md px-2.5 py-1 text-xs text-slate-300"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ),
                )}
            </div>
        </nav>
    );
}
