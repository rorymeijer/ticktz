import PortalLayout from '@/Layouts/PortalLayout';
import { ArticleList } from '@/Components/Kb/ArticleList';
import { CategoryFilter, KbSearch } from '@/Components/Kb/KbSearch';
import { Card, EmptyState, Pagination } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { Paginated } from '@/types';
import type { KbArticleSummary, KbCategorySummary } from '@/types/kb';

export default function PortalKbIndex({
    articles,
    categories,
    filters,
}: {
    articles: Paginated<KbArticleSummary>;
    categories: KbCategorySummary[];
    filters: { q: string | null; category: string | null };
}) {
    const { t } = useTranslations();

    return (
        <PortalLayout title={t('kb.portal_title')}>
            <div className="mb-6">
                <h2 className="text-2xl font-semibold tracking-tight text-slate-900">{t('kb.portal_title')}</h2>
                <p className="mt-1.5 text-sm text-slate-600">{t('kb.portal_subtitle')}</p>

                <div className="mt-5 max-w-xl">
                    <KbSearch url="/portal/kb" filters={filters} />
                </div>

                <div className="mt-4">
                    <CategoryFilter
                        url="/portal/kb"
                        categories={categories}
                        active={filters.category}
                        search={filters.q}
                    />
                </div>
            </div>

            {filters.q ? (
                <p className="mb-3 text-sm text-slate-500">{t('kb.search.results', { count: articles.total })}</p>
            ) : null}

            <Card>
                {articles.data.length > 0 ? (
                    <>
                        <ArticleList articles={articles.data} hrefFor={(article) => `/portal/kb/${article.slug}`} />
                        <Pagination page={articles} />
                    </>
                ) : (
                    <EmptyState
                        title={filters.q ? t('kb.search.empty') : t('kb.articles.empty')}
                        description={filters.q ? t('kb.search.empty_hint') : undefined}
                    />
                )}
            </Card>
        </PortalLayout>
    );
}
