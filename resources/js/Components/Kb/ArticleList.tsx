import { Link } from '@inertiajs/react';

import { IconChevronRight, IconLock } from '@/Components/Icons';
import { Badge } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';
import type { KbArticleSummary } from '@/types/kb';

/**
 * One search result. The same row serves the portal and the agent console —
 * the difference between the two is which articles reach it, and that is
 * decided by the query scopes on the server, never by a prop here.
 */
export function ArticleRow({
    article,
    href,
    showVisibility = false,
}: {
    article: KbArticleSummary;
    href: string;
    showVisibility?: boolean;
}) {
    const { t } = useTranslations();

    return (
        <Link href={href} className="group flex items-start gap-3 px-5 py-4 hover:bg-slate-50">
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900 group-hover:text-brand-700">{article.title}</span>
                    {showVisibility && article.visibility === 'internal' ? (
                        <Badge tone="amber">
                            <IconLock className="h-3 w-3" />
                            {t('kb.visibility.internal')}
                        </Badge>
                    ) : null}
                    {showVisibility && article.status !== 'published' ? (
                        <Badge tone="slate">{t(`kb.status.${article.status}`)}</Badge>
                    ) : null}
                </span>

                {article.excerpt ? (
                    <span className="mt-1 block text-sm leading-6 text-slate-500">{article.excerpt}</span>
                ) : null}

                <span className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    {article.category ? <span>{article.category.name}</span> : null}
                    {article.category ? <span aria-hidden="true">·</span> : null}
                    <span>
                        {t('kb.articles.updated_at', {
                            time: relativeTime(article.updated_at, t),
                        })}
                    </span>
                </span>
            </span>

            <IconChevronRight className="mt-1 h-4 w-4 shrink-0 text-slate-300 group-hover:text-brand-500" />
        </Link>
    );
}

export function ArticleList({
    articles,
    hrefFor,
    showVisibility = false,
}: {
    articles: KbArticleSummary[];
    hrefFor: (article: KbArticleSummary) => string;
    showVisibility?: boolean;
}) {
    return (
        <ul className="divide-y divide-slate-100">
            {articles.map((article) => (
                <li key={article.id}>
                    <ArticleRow article={article} href={hrefFor(article)} showVisibility={showVisibility} />
                </li>
            ))}
        </ul>
    );
}
