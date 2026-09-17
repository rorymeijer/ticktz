import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { RichTextField } from '@/Components/RichText/RichTextField';
import { IconLock, IconTicket, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    ConfirmDialog,
    Field,
    Select,
    TextInput,
    Textarea,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime, relativeTime } from '@/lib/datetime';
import type { KbArticleAdmin, KbArticleVersion, KbCategoryAdmin, KbOptions } from '@/types/kb';

export default function AdminKbEdit({
    article,
    versions,
    categories,
    options,
}: {
    article: KbArticleAdmin;
    versions: KbArticleVersion[];
    categories: KbCategoryAdmin[];
    options: KbOptions;
}) {
    const { t, locale } = useTranslations();
    const [deleting, setDeleting] = useState(false);
    const [restoring, setRestoring] = useState<KbArticleVersion | null>(null);

    const form = useForm({
        title: article.title,
        slug: article.slug,
        kb_category_id: article.kb_category_id ? String(article.kb_category_id) : '',
        excerpt: article.excerpt ?? '',
        body: article.body,
        status: article.status,
        visibility: article.visibility,
        locale: article.locale ?? '',
        position: article.position,
        note: '',
    });

    const save = () =>
        form.put(`/admin/kb/${article.slug}`, {
            preserveScroll: true,
            // The note describes one edit, not the article. Keeping it after a
            // save would silently attach it to the next version as well.
            onSuccess: () => form.setData('note', ''),
        });

    return (
        <AdminLayout
            title={article.title}
            actions={
                <div className="flex flex-wrap items-center gap-1.5">
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() =>
                            router.patch(`/admin/kb/${article.slug}/published`, {}, { preserveScroll: true })
                        }
                    >
                        {article.status === 'published' ? t('kb.articles.unpublish') : t('kb.articles.publish')}
                    </Button>
                    <ButtonLink href={`/agent/kb/${article.slug}`} variant="secondary" size="sm">
                        {t('common.actions.view')}
                    </ButtonLink>
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-label={t('common.actions.delete')}
                        onClick={() => setDeleting(true)}
                    >
                        <IconTrash className="h-4 w-4 text-slate-500" />
                    </Button>
                </div>
            }
        >
            <nav className="mb-4 flex items-center gap-1.5 text-xs text-slate-500">
                <Link href="/admin/kb" className="hover:text-slate-800">
                    {t('kb.title')}
                </Link>
                <span aria-hidden="true" className="text-slate-300">
                    /
                </span>
                <span className="truncate text-slate-700">{article.title}</span>
            </nav>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0 space-y-4">
                    <Card>
                        <CardBody className="space-y-4">
                            <Field label={t('kb.fields.title')} error={form.errors.title} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.title}
                                        maxLength={255}
                                        onChange={(event) => form.setData('title', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('kb.fields.excerpt')}
                                error={form.errors.excerpt}
                                help={t('kb.fields.excerpt_help')}
                            >
                                {(props) => (
                                    <Textarea
                                        {...props}
                                        rows={3}
                                        maxLength={500}
                                        value={form.data.excerpt}
                                        onChange={(event) => form.setData('excerpt', event.target.value)}
                                    />
                                )}
                            </Field>

                            {/*
                              * The write/preview toggle is gone with the raw
                              * HTML textarea it existed to compensate for.
                              * Editing in place is the preview.
                              */}
                            <RichTextField
                                label={t('kb.fields.body')}
                                profile="article"
                                images
                                value={form.data.body}
                                onChange={(html) => form.setData('body', html)}
                                error={form.errors.body}
                                help={t('kb.fields.body_help')}
                                minHeight="26rem"
                            />

                            <Field label={t('kb.fields.note')} error={form.errors.note} help={t('kb.fields.note_help')}>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.note}
                                        maxLength={255}
                                        onChange={(event) => form.setData('note', event.target.value)}
                                    />
                                )}
                            </Field>
                        </CardBody>

                        <CardFooter>
                            <span className="mr-auto text-xs text-slate-500">
                                {t('kb.versions.version', {
                                    number: article.version,
                                })}
                                {article.editor ? ` · ${t('kb.versions.by', { name: article.editor.name })}` : ''}
                            </span>
                            <ButtonLink href="/admin/kb" variant="secondary">
                                {t('common.actions.cancel')}
                            </ButtonLink>
                            <Button disabled={form.processing || !form.isDirty} onClick={save}>
                                {t('common.actions.save')}
                            </Button>
                        </CardFooter>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader title={t('kb.articles.edit')} />
                        <CardBody className="space-y-4">
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

                            <Field label={t('kb.fields.status')} error={form.errors.status}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.status}
                                        onChange={(event) =>
                                            form.setData('status', event.target.value as typeof form.data.status)
                                        }
                                    >
                                        {options.statuses.map((status) => (
                                            <option key={status} value={status}>
                                                {t(`kb.status.${status}`)}
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
                                        onChange={(event) =>
                                            form.setData(
                                                'visibility',
                                                event.target.value as typeof form.data.visibility,
                                            )
                                        }
                                    >
                                        {options.visibilities.map((visibility) => (
                                            <option key={visibility} value={visibility}>
                                                {t(`kb.visibility.${visibility}`)}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field
                                label={t('kb.fields.locale')}
                                error={form.errors.locale}
                                help={t('kb.fields.locale_help')}
                            >
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.locale}
                                        onChange={(event) => form.setData('locale', event.target.value)}
                                    >
                                        <option value="">{t('kb.fields.locale_any')}</option>
                                        {options.locales.map((code) => (
                                            <option key={code} value={code}>
                                                {code}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('kb.fields.slug')} error={form.errors.slug} help={t('kb.fields.slug_help')}>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.slug}
                                        className="font-mono text-xs"
                                        onChange={(event) => form.setData('slug', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field label={t('kb.fields.position')} error={form.errors.position}>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="number"
                                        min={0}
                                        value={form.data.position}
                                        onChange={(event) => form.setData('position', Number(event.target.value))}
                                    />
                                )}
                            </Field>

                            {article.visibility === 'internal' ? (
                                <p className="flex items-start gap-1.5 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800">
                                    <IconLock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    {t('kb.visibility.internal_help')}
                                </p>
                            ) : null}
                        </CardBody>
                    </Card>

                    {article.tickets && article.tickets.length > 0 ? (
                        <Card>
                            <CardHeader title={t('kb.articles.linked_tickets')} />
                            <ul className="divide-y divide-slate-100">
                                {article.tickets.map((ticket) => (
                                    <li key={ticket.id}>
                                        <Link
                                            href={`/agent/tickets/${ticket.key}`}
                                            className="flex items-start gap-2 px-5 py-2.5 text-sm hover:bg-slate-50"
                                        >
                                            <IconTicket className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
                                            <span className="min-w-0">
                                                <span className="font-mono text-xs font-semibold text-brand-600">
                                                    {ticket.key}
                                                </span>
                                                <span className="mt-0.5 block truncate text-slate-600">
                                                    {ticket.subject}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader title={t('kb.versions.title')} description={t('kb.versions.description')} />
                        {versions.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                <li className="flex items-center gap-2 px-5 py-2.5">
                                    <Badge tone="green">{t('kb.versions.current')}</Badge>
                                    <span className="text-xs text-slate-500">
                                        {t('kb.versions.version', {
                                            number: article.version,
                                        })}{' '}
                                        · {relativeTime(article.updated_at, t)}
                                    </span>
                                </li>
                                {versions.map((version) => (
                                    <li key={version.id} className="px-5 py-2.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-xs font-medium text-slate-700">
                                                {t('kb.versions.version', {
                                                    number: version.version,
                                                })}
                                            </span>
                                            <Button size="sm" variant="ghost" onClick={() => setRestoring(version)}>
                                                {t('kb.versions.restore')}
                                            </Button>
                                        </div>
                                        <p className="text-xs text-slate-500">{version.title}</p>
                                        {version.note ? (
                                            <p className="mt-0.5 text-xs italic text-slate-500">{version.note}</p>
                                        ) : null}
                                        <p className="mt-0.5 text-[11px] text-slate-500">
                                            {formatDateTime(version.created_at, locale)}
                                            {version.editor
                                                ? ` · ${t('kb.versions.by', { name: version.editor.name })}`
                                                : ''}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <CardBody className="text-sm text-slate-500">{t('kb.versions.empty')}</CardBody>
                        )}
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={deleting}
                message={t('kb.articles.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(false)}
                onConfirm={() => router.delete(`/admin/kb/${article.slug}`)}
            />

            <ConfirmDialog
                open={restoring !== null}
                destructive={false}
                message={t('kb.versions.confirm_restore', {
                    version: restoring?.version ?? 0,
                })}
                confirmLabel={t('kb.versions.restore')}
                onCancel={() => setRestoring(null)}
                onConfirm={() => {
                    if (!restoring) return;
                    router.post(
                        `/admin/kb/${article.slug}/versions/${restoring.id}/restore`,
                        {},
                        {
                            preserveScroll: true,
                            onFinish: () => setRestoring(null),
                        },
                    );
                }}
            />
        </AdminLayout>
    );
}
