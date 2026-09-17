import AppLayout from '@/Layouts/AppLayout';
import { ArticleList } from '@/Components/Kb/ArticleList';
import { CategoryFilter, KbSearch } from '@/Components/Kb/KbSearch';
import { ButtonLink, Card, EmptyState, Pagination } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { Paginated } from '@/types';
import type { KbArticleSummary, KbCategorySummary } from '@/types/kb';

export default function AgentKbIndex({
    articles,
    categories,
    filters,
    can,
}: {
    articles: Paginated<KbArticleSummary>;
    categories: KbCategorySummary[];
    filters: { q: string | null; category: string | null };
    can: { manage: boolean };
}) {
    const { t } = useTranslations();

    return (
        <AppLayout
            title={t('kb.title')}
            header={t('kb.title')}
            actions={
                can.manage ? (
                    <ButtonLink href="/admin/kb" variant="secondary" size="sm">
                        {t('kb.articles.title')}
                    </ButtonLink>
                ) : null
            }
        >
            <div className="mb-5 max-w-xl">
                <KbSearch url="/agent/kb" filters={filters} />
            </div>

            <div className="mb-4">
                <CategoryFilter url="/agent/kb" categories={categories} active={filters.category} search={filters.q} />
            </div>

            {filters.q ? (
                <p className="mb-3 text-sm text-slate-500">{t('kb.search.results', { count: articles.total })}</p>
            ) : null}

            <Card>
                {articles.data.length > 0 ? (
                    <>
                        {/* Visibility is shown here and not on the portal: an
                            agent needs to know whether the answer they are
                            about to paste is one the requester may read. */}
                        <ArticleList
                            articles={articles.data}
                            hrefFor={(article) => `/agent/kb/${article.slug}`}
                            showVisibility
                        />
                        <Pagination page={articles} />
                    </>
                ) : (
                    <EmptyState
                        title={filters.q ? t('kb.search.empty') : t('kb.articles.empty')}
                        description={filters.q ? t('kb.search.empty_hint') : t('kb.articles.empty_hint')}
                        action={can.manage ? <ButtonLink href="/admin/kb">{t('kb.articles.add')}</ButtonLink> : null}
                    />
                )}
            </Card>
        </AppLayout>
    );
}
