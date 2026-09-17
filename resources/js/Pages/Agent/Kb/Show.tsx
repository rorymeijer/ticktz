import { Link } from '@inertiajs/react';

import AppLayout from '@/Layouts/AppLayout';
import { ArticleBody } from '@/Components/Kb/ArticleBody';
import { IconLock } from '@/Components/Icons';
import { Badge, ButtonLink, Card, CardBody } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDate } from '@/lib/datetime';
import type { KbArticleDisplay } from '@/types/kb';

export default function AgentKbShow({ article, can }: { article: KbArticleDisplay; can: { manage: boolean } }) {
    const { t, locale } = useTranslations();

    return (
        <AppLayout
            title={article.title}
            header={
                <span className="flex items-center gap-2">
                    <Link href="/agent/kb" className="text-slate-500 hover:text-slate-600">
                        {t('kb.title')}
                    </Link>
                    <span aria-hidden="true" className="text-slate-300">
                        /
                    </span>
                    <span className="truncate">{article.title}</span>
                </span>
            }
            actions={
                can.manage ? (
                    <ButtonLink href={`/admin/kb/${article.slug}/edit`} variant="secondary" size="sm">
                        {t('common.actions.edit')}
                    </ButtonLink>
                ) : null
            }
        >
            <div className="mx-auto max-w-3xl">
                <Card>
                    <CardBody>
                        <div className="flex flex-wrap items-center gap-2">
                            {article.category ? <Badge tone="blue">{article.category.name}</Badge> : null}
                            {article.visibility === 'internal' ? (
                                <Badge tone="amber">
                                    <IconLock className="h-3 w-3" />
                                    {t('kb.visibility.internal')}
                                </Badge>
                            ) : null}
                            {article.status !== 'published' ? (
                                <Badge tone="slate">{t(`kb.status.${article.status}`)}</Badge>
                            ) : null}
                        </div>

                        <h1 className="mt-2.5 text-2xl font-semibold tracking-tight text-slate-900">{article.title}</h1>

                        <p className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            {article.author ? (
                                <span>
                                    {t('kb.articles.by', {
                                        name: article.author.name,
                                    })}
                                </span>
                            ) : null}
                            {article.published_at ? (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <time dateTime={article.published_at}>
                                        {formatDate(article.published_at, locale)}
                                    </time>
                                </>
                            ) : null}
                            <span aria-hidden="true">·</span>
                            <span>
                                {t('kb.articles.views', {
                                    count: article.view_count,
                                })}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>
                                {t('kb.versions.version', {
                                    number: article.version,
                                })}
                            </span>
                        </p>

                        <div className="mt-5 border-t border-slate-100 pt-5">
                            <ArticleBody html={article.body} />
                        </div>
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
