import { useEffect, useState } from 'react';

import { IconBook, IconChevronRight } from '@/Components/Icons';
import { useTranslations } from '@/hooks/useTranslations';
import type { KbArticleSummary } from '@/types/kb';

/**
 * Answers offered while the requester is still typing the question.
 *
 * The cheapest ticket is the one nobody had to file, so this sits beside the
 * subject field rather than behind a "search first" step somebody would skip.
 * It renders nothing at all until there is something to show: an empty box
 * that says "no matches" while you type a word is noise beside a form.
 */
export function ArticleSuggestions({ subject }: { subject: string }) {
    const { t } = useTranslations();
    const [articles, setArticles] = useState<KbArticleSummary[]>([]);

    useEffect(() => {
        const term = subject.trim();

        if (term.length < 4) {
            setArticles([]);

            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            fetch(`/portal/kb/suggest?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: { articles: KbArticleSummary[] }) => setArticles(data.articles))
                // Aborted by the next keystroke, or the endpoint is having a
                // bad day. Either way the form still works, so it stays quiet.
                .catch(() => undefined);
        }, 400);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [subject]);

    if (articles.length === 0) {
        return null;
    }

    return (
        <aside className="rounded-xl border border-sky-200 bg-sky-50 p-4" aria-live="polite">
            <p className="flex items-center gap-1.5 text-sm font-semibold text-sky-900">
                <IconBook className="h-4 w-4" />
                {t('kb.suggestions.portal_title')}
            </p>
            <p className="mt-0.5 text-xs text-sky-800">{t('kb.suggestions.portal_hint')}</p>

            <ul className="mt-2.5 space-y-1">
                {articles.map((article) => (
                    <li key={article.id}>
                        {/* A plain anchor, opened in a new tab: following an
                            Inertia link here would throw away a half-filled
                            form, and the answer might not be the answer. */}
                        <a
                            href={`/portal/kb/${article.slug}`}
                            target="_blank"
                            rel="noreferrer"
                            className="group flex items-center gap-1.5 text-sm font-medium text-sky-900 hover:text-sky-700"
                        >
                            <span className="min-w-0 flex-1 truncate underline underline-offset-2">
                                {article.title}
                            </span>
                            <IconChevronRight className="h-3.5 w-3.5 shrink-0 text-sky-400" />
                        </a>
                    </li>
                ))}
            </ul>
        </aside>
    );
}
