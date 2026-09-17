import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { IconBook, IconLock, IconSearch, IconX } from '@/Components/Icons';
import { Badge, Button, Card, CardBody, CardHeader, Modal, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { KbArticleSummary } from '@/types/kb';

/**
 * The knowledge base beside a ticket.
 *
 * Two jobs in one panel: show what this ticket was answered with, and offer
 * what it probably should be. The suggestions come from the ticket's own
 * subject, so the search box starts with an answer rather than a cursor — and
 * linking the article that actually helped is what turns "what do we get asked
 * most" from a guess into a query.
 */
export function KbPanel({
    ticketKey,
    articles,
    canLink,
}: {
    ticketKey: string;
    articles: KbArticleSummary[];
    canLink: boolean;
}) {
    const { t } = useTranslations();
    const [picking, setPicking] = useState(false);

    return (
        <Card>
            <CardHeader
                title={t('kb.ticket.title')}
                actions={
                    canLink ? (
                        <Button size="sm" variant="ghost" onClick={() => setPicking(true)}>
                            {t('kb.ticket.add')}
                        </Button>
                    ) : null
                }
            />

            {articles.length > 0 ? (
                <ul className="divide-y divide-slate-100">
                    {articles.map((article) => (
                        <li key={article.id} className="flex items-start gap-2 px-5 py-2.5">
                            <IconBook className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
                            <span className="min-w-0 flex-1">
                                <Link
                                    href={`/agent/kb/${article.slug}`}
                                    className="block text-sm font-medium text-slate-800 hover:text-brand-700"
                                >
                                    {article.title}
                                </Link>
                                {article.visibility === 'internal' ? (
                                    <Badge tone="amber" className="mt-1">
                                        <IconLock className="h-3 w-3" />
                                        {t('kb.visibility.internal')}
                                    </Badge>
                                ) : null}
                            </span>
                            {canLink ? (
                                <button
                                    type="button"
                                    aria-label={t('kb.ticket.unlink')}
                                    onClick={() =>
                                        router.delete(`/agent/tickets/${ticketKey}/kb/${article.slug}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="rounded p-1 text-slate-500 hover:text-red-600"
                                >
                                    <IconX className="h-3.5 w-3.5" />
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <CardBody className="text-sm text-slate-500">{t('kb.ticket.empty')}</CardBody>
            )}

            {picking ? <LinkDialog ticketKey={ticketKey} onClose={() => setPicking(false)} /> : null}
        </Card>
    );
}

/**
 * Search-and-link. Suggestions are fetched rather than shipped with the page:
 * the search changes on every keystroke, and sending the whole knowledge base
 * to every ticket page so it could be filtered here would be the wrong trade
 * at any desk worth the name.
 */
function LinkDialog({ ticketKey, onClose }: { ticketKey: string; onClose: () => void }) {
    const { t } = useTranslations();
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<KbArticleSummary[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const controller = new AbortController();
        const timeout = setTimeout(() => {
            setLoading(true);

            fetch(`/agent/tickets/${ticketKey}/kb/suggest?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: { articles: KbArticleSummary[] }) => setResults(data.articles))
                // An aborted request is the previous keystroke being replaced,
                // not a failure worth showing anybody.
                .catch(() => undefined)
                .finally(() => setLoading(false));
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [term, ticketKey]);

    return (
        <Modal open onClose={onClose} title={t('kb.ticket.add')} size="lg">
            <div className="relative">
                <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                <TextInput
                    type="search"
                    autoFocus
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder={t('kb.ticket.search')}
                    aria-label={t('kb.ticket.search')}
                    className="pl-9"
                />
            </div>

            <div className="mt-3 max-h-80 overflow-y-auto">
                {results.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {results.map((article) => (
                            <li key={article.id} className="flex items-start gap-3 py-2.5">
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium text-slate-800">{article.title}</span>
                                        {article.visibility === 'internal' ? (
                                            <Badge tone="amber">
                                                <IconLock className="h-3 w-3" />
                                                {t('kb.visibility.internal')}
                                            </Badge>
                                        ) : null}
                                    </span>
                                    {article.excerpt ? (
                                        <span className="mt-0.5 block text-xs leading-5 text-slate-500">
                                            {article.excerpt}
                                        </span>
                                    ) : null}
                                </span>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            `/agent/tickets/${ticketKey}/kb`,
                                            { kb_article_id: article.id },
                                            {
                                                preserveScroll: true,
                                                onSuccess: onClose,
                                            },
                                        )
                                    }
                                >
                                    {t('kb.ticket.link')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="py-6 text-center text-sm text-slate-500">
                        {loading ? t('kb.suggestions.searching') : t('kb.suggestions.none')}
                    </p>
                )}
            </div>
        </Modal>
    );
}
