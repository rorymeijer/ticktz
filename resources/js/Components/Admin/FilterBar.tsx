import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

import { IconSearch } from '@/Components/Icons';
import { Button, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';

/**
 * Debounced server-side search/filter bar. Filters live in the query string so
 * a filtered list is a shareable, bookmarkable URL and the back button works.
 */
export function FilterBar({
    url,
    filters,
    searchPlaceholder,
    children,
}: {
    url: string;
    filters: Record<string, string | undefined>;
    searchPlaceholder?: string;
    children?: ReactNode;
}) {
    const { t } = useTranslations();
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        if ((filters.search ?? '') === search) {
            return;
        }

        const timeout = setTimeout(() => {
            router.get(
                url,
                { ...filters, search: search || undefined },
                { preserveState: true, replace: true, preserveScroll: true },
            );
        }, 300);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
            <div className="relative min-w-52 flex-1">
                <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <TextInput
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={searchPlaceholder ?? t('common.actions.search')}
                    aria-label={t('common.actions.search')}
                    className="pl-9"
                />
            </div>

            {children}

            {hasFilters ? (
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setSearch('');
                        router.get(url, {}, { preserveState: false, replace: true });
                    }}
                >
                    {t('common.actions.reset')}
                </Button>
            ) : null}
        </div>
    );
}

/**
 * A `<select>` that pushes its value straight into the query string.
 */
export function FilterSelect({
    url,
    filters,
    name,
    label,
    options,
}: {
    url: string;
    filters: Record<string, string | undefined>;
    name: string;
    label: string;
    options: { value: string; label: string }[];
}) {
    return (
        <select
            aria-label={label}
            value={filters[name] ?? ''}
            onChange={(event) =>
                router.get(
                    url,
                    { ...filters, [name]: event.target.value || undefined },
                    { preserveState: true, replace: true, preserveScroll: true },
                )
            }
            className="h-9 rounded-lg border-slate-300 bg-white py-0 text-sm text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500"
        >
            <option value="">{label}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}
