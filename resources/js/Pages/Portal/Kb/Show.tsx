import { Link } from '@inertiajs/react';

import PortalLayout from '@/Layouts/PortalLayout';
import { ArticleBody } from '@/Components/Kb/ArticleBody';
import { ArticleList } from '@/Components/Kb/ArticleList';
import { IconChevronRight } from '@/Components/Icons';
import { Card, CardBody, CardHeader } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDate } from '@/lib/datetime';
import type { KbArticleDisplay, KbArticleSummary } from '@/types/kb';

export default function PortalKbShow({ article, related }: { article: KbArticleDisplay; related: KbArticleSummary[] }) {
    const { t, locale } = useTranslations();

    return (
        <PortalLayout title={article.title}>
            <nav aria-label={t('kb.portal_title')} className="mb-4 flex items-center gap-1.5 text-xs text-slate-500">
                <Link href="/portal/kb" className="hover:text-slate-800">
                    {t('kb.portal_title')}
                </Link>
                {article.category ? (
                    <>
                        <IconChevronRight className="h-3 w-3 text-slate-300" />
                        <Link href={`/portal/kb?category=${article.category.slug}`} className="hover:text-slate-800">
                            {article.category.name}
                        </Link>
                    </>
                ) : null}
            </nav>

            <Card>
                <CardBody>
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{article.title}</h1>

                    <p className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        {article.published_at ? (
                            <time dateTime={article.published_at}>{formatDate(article.published_at, locale)}</time>
                        ) : null}
                        <span aria-hidden="true">·</span>
                        <span>
                            {t('kb.articles.views', {
                                count: article.view_count,
                            })}
                        </span>
                    </p>

                    <div className="mt-5 border-t border-slate-100 pt-5">
                        <ArticleBody html={article.body} />
                    </div>
                </CardBody>
            </Card>

            {related.length > 0 ? (
                <Card className="mt-6">
                    <CardHeader title={t('kb.articles.related')} />
                    <ArticleList articles={related} hrefFor={(item) => `/portal/kb/${item.slug}`} />
                </Card>
            ) : null}
        </PortalLayout>
    );
}
