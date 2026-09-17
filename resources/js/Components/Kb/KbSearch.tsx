import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { IconSearch, IconX } from '@/Components/Icons';
import { TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { KbCategorySummary } from '@/types/kb';

/**
 * Debounced search that lives in the query string.
 *
 * The same reason as every other list in Ticktz: a search is then a URL you
 * can send to a colleague, and the back button behaves. Filtering happens on
 * the server — a knowledge base outgrows client-side filtering the moment it
 * is worth having.
 */
export function KbSearch({ url, filters }: { url: string; filters: { q: string | null; category: string | null } }) {
    const { t } = useTranslations();
    const [term, setTerm] = useState(filters.q ?? '');

    useEffect(() => {
        if ((filters.q ?? '') === term) {
            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                url,
                {
                    q: term || undefined,
                    category: filters.category || undefined,
                },
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className="relative">
            <IconSearch className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            <TextInput
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={t('kb.search.placeholder')}
                aria-label={t('kb.search.placeholder')}
                className="h-11 pl-10 pr-10"
            />
            {term ? (
                <button
                    type="button"
                    onClick={() => setTerm('')}
                    aria-label={t('kb.search.clear')}
                    className="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1 text-slate-500 hover:text-slate-700"
                >
                    <IconX className="h-3.5 w-3.5" />
                </button>
            ) : null}
        </div>
    );
}

/**
 * The category rail. A category is a link rather than a select so the browser
 * treats it as navigation, which is what it is.
 */
export function CategoryFilter({
    url,
    categories,
    active,
    search,
}: {
    url: string;
    categories: KbCategorySummary[];
    active: string | null;
    search: string | null;
}) {
    const { t } = useTranslations();

    if (categories.length === 0) {
        return null;
    }

    const href = (slug: string | null) => {
        const query = new URLSearchParams();
        if (search) query.set('q', search);
        if (slug) query.set('category', slug);
        const suffix = query.toString();

        return suffix ? `${url}?${suffix}` : url;
    };

    const entries: {
        slug: string | null;
        name: string;
        count: number | null;
    }[] = [
        { slug: null, name: t('kb.categories.all'), count: null },
        ...categories.map((category) => ({
            slug: category.slug,
            name: category.name,
            count: category.article_count,
        })),
    ];

    return (
        <nav aria-label={t('kb.categories.title')} className="flex flex-wrap gap-1.5">
            {entries.map((entry) => {
                const current = (entry.slug ?? null) === active;

                return (
                    <a
                        key={entry.slug ?? '__all'}
                        href={href(entry.slug)}
                        aria-current={current ? 'page' : undefined}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-medium transition-colors',
                            current
                                ? 'bg-brand-600 text-white'
                                : 'bg-white text-slate-600 shadow-card hover:text-slate-900',
                        )}
                    >
                        {entry.name}
                        {entry.count !== null ? (
                            <span className={cn('text-xs', current ? 'text-white/70' : 'text-slate-500')}>
                                {entry.count}
                            </span>
                        ) : null}
                    </a>
                );
            })}
        </nav>
    );
}
