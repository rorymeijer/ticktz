import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { TranslationsField } from '@/Components/Admin/TranslationsField';
import { FilterSelect } from '@/Components/Admin/FilterBar';
import { IconLock, IconPlus, IconSearch, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Checkbox,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    Pagination,
    PageHeader,
    Select,
    Table,
    TBody,
    TD,
    TextInput,
    Textarea,
    TH,
    THead,
    TR,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';
import type { Paginated } from '@/types';
import type { KbArticleAdmin, KbCategoryAdmin, KbOptions, Visibility } from '@/types/kb';

export default function AdminKbIndex({
    articles,
    categories,
    filters,
    options,
}: {
    articles: Paginated<KbArticleAdmin>;
    categories: KbCategoryAdmin[];
    filters: {
        q: string | null;
        status: string | null;
        visibility: string | null;
        category: number | null;
    };
    options: KbOptions;
}) {
    const { t } = useTranslations();
    const [writing, setWriting] = useState(false);
    const [editingCategory, setEditingCategory] = useState<KbCategoryAdmin | null>(null);
    const [addingCategory, setAddingCategory] = useState(false);
    const [deletingCategory, setDeletingCategory] = useState<KbCategoryAdmin | null>(null);

    const queryFilters = {
        q: filters.q ?? undefined,
        status: filters.status ?? undefined,
        visibility: filters.visibility ?? undefined,
        category: filters.category ? String(filters.category) : undefined,
    };

    return (
        <AdminLayout
            title={t('kb.title')}
            actions={
                <Button icon={<IconPlus className="h-4 w-4" />} onClick={() => setWriting(true)}>
                    {t('kb.articles.add')}
                </Button>
            }
        >
            <PageHeader title={t('kb.title')} description={t('kb.subtitle')} />

            <Card>
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                    <SearchField value={filters.q ?? ''} filters={queryFilters} />

                    <FilterSelect
                        url="/admin/kb"
                        filters={queryFilters}
                        name="status"
                        label={t('kb.filters.any_status')}
                        options={options.statuses.map((status) => ({
                            value: status,
                            label: t(`kb.status.${status}`),
                        }))}
                    />

                    <FilterSelect
                        url="/admin/kb"
                        filters={queryFilters}
                        name="visibility"
                        label={t('kb.filters.any_visibility')}
                        options={options.visibilities.map((visibility) => ({
                            value: visibility,
                            label: t(`kb.visibility.${visibility}`),
                        }))}
                    />

                    <FilterSelect
                        url="/admin/kb"
                        filters={queryFilters}
                        name="category"
                        label={t('kb.filters.any_category')}
                        options={categories.map((category) => ({
                            value: String(category.id),
                            label: category.name,
                        }))}
                    />
                </div>

                {articles.data.length > 0 ? (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('kb.fields.title')}</TH>
                                    <TH>{t('kb.fields.category')}</TH>
                                    <TH>{t('kb.fields.status')}</TH>
                                    <TH>{t('kb.fields.visibility')}</TH>
                                    <TH className="text-right">{t('kb.articles.views_label')}</TH>
                                    <TH>{t('common.labels.updated_at')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {articles.data.map((article) => (
                                    <TR key={article.id}>
                                        <TD>
                                            <Link
                                                href={`/admin/kb/${article.slug}/edit`}
                                                className="font-medium text-slate-900 hover:text-brand-700"
                                            >
                                                {article.title}
                                            </Link>
                                            {article.ticket_count ? (
                                                <span className="ml-2 text-xs text-slate-400">
                                                    {t('kb.articles.ticket_count', {
                                                        count: article.ticket_count,
                                                    })}
                                                </span>
                                            ) : null}
                                        </TD>
                                        <TD className="text-slate-500">
                                            {article.category?.name ?? t('kb.categories.uncategorised')}
                                        </TD>
                                        <TD>
                                            <Badge tone={article.status === 'published' ? 'green' : 'slate'}>
                                                {t(`kb.status.${article.status}`)}
                                            </Badge>
                                        </TD>
                                        <TD>
                                            <Badge tone={article.visibility === 'internal' ? 'amber' : 'blue'}>
                                                {article.visibility === 'internal' ? (
                                                    <IconLock className="h-3 w-3" />
                                                ) : null}
                                                {t(`kb.visibility.${article.visibility}`)}
                                            </Badge>
                                        </TD>
                                        <TD className="text-right tabular-nums text-slate-500">{article.view_count}</TD>
                                        <TD className="text-slate-500">{relativeTime(article.updated_at, t)}</TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={articles} />
                    </>
                ) : (
                    <EmptyState
                        title={t('kb.articles.empty')}
                        description={t('kb.articles.empty_hint')}
                        action={<Button onClick={() => setWriting(true)}>{t('kb.articles.add')}</Button>}
                    />
                )}
            </Card>

            <Card className="mt-6">
                <CardHeader
                    title={t('kb.categories.title')}
                    actions={
                        <Button size="sm" variant="secondary" onClick={() => setAddingCategory(true)}>
                            {t('kb.categories.add')}
                        </Button>
                    }
                />
                {categories.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {categories.map((category) => (
                            <li key={category.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        {/* Nested categories are indented rather than
                                            given their own tree: one level deep is all
                                            the data model allows, so a tree would be
                                            scaffolding around a single indent. */}
                                        {category.parent_id ? (
                                            <span aria-hidden="true" className="text-slate-300">
                                                └
                                            </span>
                                        ) : null}
                                        <span className="font-medium text-slate-900">{category.name}</span>
                                        {category.visibility === 'internal' ? (
                                            <Badge tone="amber">
                                                <IconLock className="h-3 w-3" />
                                                {t('kb.visibility.internal')}
                                            </Badge>
                                        ) : null}
                                        {!category.is_active ? (
                                            <Badge tone="slate">{t('common.labels.inactive')}</Badge>
                                        ) : null}
                                    </span>
                                    {category.description ? (
                                        <span className="mt-0.5 block text-xs text-slate-500">
                                            {category.description}
                                        </span>
                                    ) : null}
                                </span>

                                <span className="text-xs text-slate-400">
                                    {t('kb.categories.article_count', {
                                        count: category.article_count ?? 0,
                                    })}
                                </span>

                                <Button size="sm" variant="ghost" onClick={() => setEditingCategory(category)}>
                                    {t('common.actions.edit')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('common.actions.delete')}
                                    onClick={() => setDeletingCategory(category)}
                                >
                                    <IconTrash className="h-4 w-4 text-slate-400" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyState title={t('kb.categories.empty')} />
                )}
            </Card>

            {writing ? (
                <NewArticleDialog categories={categories} options={options} onClose={() => setWriting(false)} />
            ) : null}

            {addingCategory || editingCategory ? (
                <CategoryDialog
                    category={editingCategory}
                    categories={categories}
                    onClose={() => {
                        setAddingCategory(false);
                        setEditingCategory(null);
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={deletingCategory !== null}
                message={t('kb.categories.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeletingCategory(null)}
                onConfirm={() => {
                    if (!deletingCategory) return;
                    router.delete(`/admin/kb/categories/${deletingCategory.id}`, {
                        preserveScroll: true,
                        onFinish: () => setDeletingCategory(null),
                    });
                }}
            />
        </AdminLayout>
    );
}

/**
 * Debounced search. Separate from {@link FilterBar} because that component
 * keys its term on `search` and the knowledge base uses `q` throughout — the
 * portal, the agent console and the suggestion endpoints all speak `q`, and
 * one of the two names had to give.
 */
function SearchField({ value, filters }: { value: string; filters: Record<string, string | undefined> }) {
    const { t } = useTranslations();
    const [term, setTerm] = useState(value);

    return (
        <form
            className="relative min-w-52 flex-1"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(
                    '/admin/kb',
                    { ...filters, q: term || undefined },
                    {
                        preserveState: true,
                        replace: true,
                        preserveScroll: true,
                    },
                );
            }}
        >
            <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <TextInput
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={t('kb.search.placeholder')}
                aria-label={t('kb.search.placeholder')}
                className="pl-9"
            />
        </form>
    );
}

/**
 * Creating an article asks for a title and nothing else worth arguing about:
 * everything else is easier to decide once you are looking at the editor.
 */
function NewArticleDialog({
    categories,
    options,
    onClose,
}: {
    categories: KbCategoryAdmin[];
    options: KbOptions;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({
        title: '',
        kb_category_id: '',
        body: '',
        excerpt: '',
        status: 'draft',
        visibility: 'public',
        locale: '',
        position: 100,
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={t('kb.articles.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || form.data.title.trim() === ''}
                        onClick={() => form.post('/admin/kb')}
                    >
                        {t('common.actions.create')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('kb.fields.title')} error={form.errors.title} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            autoFocus
                            value={form.data.title}
                            maxLength={255}
                            onChange={(event) => form.setData('title', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('kb.fields.category')} error={form.errors.kb_category_id}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.kb_category_id}
                            onChange={(event) => form.setData('kb_category_id', event.target.value)}
                        >
                            <option value="">{t('kb.categories.uncategorised')}</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('kb.fields.visibility')} error={form.errors.visibility}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.visibility}
                            onChange={(event) => form.setData('visibility', event.target.value)}
                        >
                            {options.visibilities.map((visibility) => (
                                <option key={visibility} value={visibility}>
                                    {t(`kb.visibility.${visibility}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('kb.fields.body')} error={form.errors.body} help={t('kb.fields.body_help')} required>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={6}
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                        />
                    )}
                </Field>
            </div>
        </Modal>
    );
}

function CategoryDialog({
    category,
    categories,
    onClose,
}: {
    category: KbCategoryAdmin | null;
    categories: KbCategoryAdmin[];
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({
        name: category?.name ?? '',
        name_translations: category?.name_translations ?? {},
        slug: category?.slug ?? '',
        description: category?.description ?? '',
        description_translations: category?.description_translations ?? {},
        icon: category?.icon ?? '',
        parent_id: category?.parent_id ? String(category.parent_id) : '',
        visibility: category?.visibility ?? 'public',
        is_active: category?.is_active ?? true,
        position: category?.position ?? 100,
    });

    const submit = () => {
        category
            ? form.put(`/admin/kb/categories/${category.id}`, {
                  preserveScroll: true,
                  onSuccess: onClose,
              })
            : form.post('/admin/kb/categories', {
                  preserveScroll: true,
                  onSuccess: onClose,
              });
    };

    // Only a top-level category can be a parent, and nothing can parent itself.
    const parents = categories.filter((option) => option.parent_id === null && option.id !== category?.id);

    return (
        <Modal
            open
            onClose={onClose}
            title={category ? t('kb.categories.edit') : t('kb.categories.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button disabled={form.processing} onClick={submit}>
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('common.labels.name')} error={form.errors.name} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => {
                                form.setData('name', event.target.value);

                                // A slug follows the name until somebody has
                                // typed one; after that it is theirs to keep,
                                // because a published URL must not move.
                                if (!category && form.data.slug === '') {
                                    form.setData(
                                        'slug',
                                        event.target.value
                                            .toLowerCase()
                                            .normalize('NFKD')
                                            .replace(/[^a-z0-9]+/g, '-')
                                            .replace(/^-|-$/g, ''),
                                    );
                                }
                            }}
                        />
                    )}
                </Field>

                <TranslationsField
                    label={t('kb.categories.name_translations')}
                    values={form.data.name_translations}
                    onChange={(values) => form.setData('name_translations', values)}
                />

                <Field label={t('kb.fields.slug')} error={form.errors.slug} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.slug}
                            onChange={(event) => form.setData('slug', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={2}
                            value={form.data.description ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label={t('kb.categories.parent')}
                    error={form.errors.parent_id}
                    help={t('kb.categories.parent_help')}
                >
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.parent_id}
                            onChange={(event) => form.setData('parent_id', event.target.value)}
                        >
                            <option value="">{t('kb.categories.parent_none')}</option>
                            {parents.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field
                    label={t('kb.fields.visibility')}
                    error={form.errors.visibility}
                    help={t(`kb.visibility.${form.data.visibility}_help`)}
                >
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.visibility}
                            onChange={(event) => form.setData('visibility', event.target.value as Visibility)}
                        >
                            <option value="public">{t('kb.visibility.public')}</option>
                            <option value="internal">{t('kb.visibility.internal')}</option>
                        </Select>
                    )}
                </Field>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <Checkbox
                        checked={form.data.is_active}
                        onChange={(event) => form.setData('is_active', event.target.checked)}
                    />
                    {t('common.labels.active')}
                </label>
            </div>
        </Modal>
    );
}
