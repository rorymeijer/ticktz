/**
 * Shapes the knowledge base screens receive from the server. These mirror
 * `KbArticle::toSummaryArray()` / `toDisplayArray()` / `toAdminArray()` and
 * `KbCategory::toSummaryArray()` / `toAdminArray()` — when one changes, change
 * the other.
 */

import type { UserSummary } from '@/types/tickets';

export type ArticleStatus = 'draft' | 'published' | 'archived';

export type Visibility = 'public' | 'internal';

export interface KbCategorySummary {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon: string | null;
    visibility: Visibility;
    parent_id: number | null;
    article_count: number | null;
}

export interface KbCategoryAdmin extends KbCategorySummary {
    name_translations: Record<string, string>;
    description_translations: Record<string, string>;
    is_active: boolean;
    position: number;
}

export interface KbArticleSummary {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    category: { name: string; slug: string } | null;
    visibility: Visibility;
    status: ArticleStatus;
    updated_at: string | null;
}

/**
 * `body` is sanitised server-side, in {@link ArticleSanitizer}, before it is
 * ever stored. That is what makes it safe to render as markup here — nothing
 * on this side of the wire is allowed to be the thing that keeps it safe.
 */
export interface KbArticleDisplay extends KbArticleSummary {
    body: string;
    locale: string | null;
    author: UserSummary | null;
    published_at: string | null;
    view_count: number;
    version: number;
}

export interface KbArticleAdmin extends KbArticleDisplay {
    kb_category_id: number | null;
    editor: UserSummary | null;
    position: number;
    ticket_count: number | null;
    created_at: string | null;
    tickets?: { id: number; key: string; subject: string }[];
}

export interface KbArticleVersion {
    id: number;
    version: number;
    title: string;
    note: string | null;
    editor: UserSummary | null;
    created_at: string | null;
}

export interface KbOptions {
    statuses: ArticleStatus[];
    visibilities: Visibility[];
    locales: string[];
}
