import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconLock, IconPlus, IconTrash } from '@/Components/Icons';
import { RichTextEditor } from '@/Components/RichText/RichTextEditor';
import {
    Badge,
    Button,
    Card,
    Checkbox,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Select,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { isBlankHtml } from '@/lib/richtext';
import { slugify } from '@/lib/slug';

interface ReplyTemplate {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    body: string;
    team_id: number | null;
    team: { id: number; name: string } | null;
    locale: string | null;
    is_internal: boolean;
    is_active: boolean;
    position: number;
}

interface PageOptions {
    teams: { id: number; name: string }[];
    locales: string[];
    /** The `{{ token }}` vocabulary, grouped, straight from the renderer. */
    placeholders: Record<string, string[]>;
}

/**
 * The words the desk says twice.
 *
 * The list shows the two things that decide where a template turns up — reply
 * or note, and which team — as badges rather than in the edit dialog only. A
 * template an agent cannot find is a template somebody rewrites by hand.
 */
export default function ReplyTemplatesIndex({
    templates,
    teams,
    locales,
    placeholders,
}: { templates: ReplyTemplate[] } & PageOptions) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<ReplyTemplate | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<ReplyTemplate | null>(null);

    const options: PageOptions = { teams, locales, placeholders };

    return (
        <AdminLayout
            title={t('admin.reply_templates.title')}
            actions={
                <Button icon={<IconPlus className="h-4 w-4" />} onClick={() => setCreating(true)}>
                    {t('admin.reply_templates.add')}
                </Button>
            }
        >
            <PageHeader
                title={t('admin.reply_templates.title')}
                description={t('admin.reply_templates.subtitle')}
            />

            <Card>
                {templates.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {templates.map((template) => (
                            <li key={template.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-slate-900">{template.name}</span>

                                        {template.is_internal ? (
                                            <Badge tone="amber">
                                                <IconLock className="mr-1 inline h-3 w-3" />
                                                {t('admin.reply_templates.internal')}
                                            </Badge>
                                        ) : (
                                            <Badge tone="blue">{t('admin.reply_templates.reply')}</Badge>
                                        )}

                                        {template.team ? <Badge tone="slate">{template.team.name}</Badge> : null}
                                        {template.locale ? (
                                            <Badge tone="slate">{template.locale.toUpperCase()}</Badge>
                                        ) : null}
                                        {!template.is_active ? (
                                            <Badge tone="slate">{t('common.labels.inactive')}</Badge>
                                        ) : null}
                                    </span>

                                    {template.description ? (
                                        <span className="mt-0.5 block text-xs text-slate-500">
                                            {template.description}
                                        </span>
                                    ) : null}
                                </span>

                                <Button size="sm" variant="ghost" onClick={() => setEditing(template)}>
                                    {t('common.actions.edit')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('common.actions.delete')}
                                    onClick={() => setDeleting(template)}
                                >
                                    <IconTrash className="h-4 w-4 text-slate-500" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyState
                        title={t('admin.reply_templates.empty')}
                        description={t('admin.reply_templates.empty_hint')}
                        action={
                            <Button onClick={() => setCreating(true)}>{t('admin.reply_templates.add')}</Button>
                        }
                    />
                )}
            </Card>

            {creating || editing ? (
                <TemplateDialog
                    template={editing}
                    options={options}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={deleting !== null}
                message={t('admin.reply_templates.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(`/admin/reply-templates/${deleting.id}`, {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    });
                }}
            />
        </AdminLayout>
    );
}

function TemplateDialog({
    template,
    options,
    onClose,
}: {
    template: ReplyTemplate | null;
    options: PageOptions;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        name: template?.name ?? '',
        slug: template?.slug ?? '',
        description: template?.description ?? '',
        body: template?.body ?? '',
        team_id: template?.team_id ?? null,
        locale: template?.locale ?? null,
        is_internal: template?.is_internal ?? false,
        is_active: template?.is_active ?? true,
        position: template?.position ?? 100,
    });

    const submit = () => {
        template
            ? form.put(`/admin/reply-templates/${template.id}`, { preserveScroll: true, onSuccess: onClose })
            : form.post('/admin/reply-templates', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={template ? t('admin.reply_templates.edit') : t('admin.reply_templates.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || form.data.name.trim() === '' || isBlankHtml(form.data.body)}
                        onClick={submit}
                    >
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('common.labels.name')} error={form.errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                autoFocus
                                value={form.data.name}
                                onChange={(event) => {
                                    form.setData('name', event.target.value);

                                    // The slug follows the name until the
                                    // template has one. After it is saved the
                                    // slug is what anything else refers to it
                                    // by, so a rename must not move it.
                                    if (!template) {
                                        form.setData('slug', slugify(event.target.value));
                                    }
                                }}
                            />
                        )}
                    </Field>

                    <Field label={t('common.labels.slug')} error={form.errors.slug} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.slug}
                                className="font-mono text-xs"
                                onChange={(event) => form.setData('slug', event.target.value)}
                            />
                        )}
                    </Field>
                </div>

                <Field
                    label={t('common.labels.description')}
                    error={form.errors.description}
                    help={t('admin.reply_templates.description_help')}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                <div>
                    <RichTextEditor
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        label={t('admin.reply_templates.body')}
                        placeholder={t('admin.reply_templates.body_placeholder')}
                        invalid={Boolean(form.errors.body)}
                        internal={form.data.is_internal}
                        minHeight="8rem"
                    />
                    {form.errors.body ? <p className="mt-1 text-xs text-red-600">{form.errors.body}</p> : null}
                </div>

                <PlaceholderSheet groups={options.placeholders} />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.reply_templates.team')} help={t('admin.reply_templates.team_help')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={String(form.data.team_id ?? '')}
                                onChange={(event) =>
                                    form.setData('team_id', Number(event.target.value) || null)
                                }
                            >
                                <option value="">{t('admin.reply_templates.every_team')}</option>
                                {options.teams.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label={t('admin.reply_templates.locale')} help={t('admin.reply_templates.locale_help')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.locale ?? ''}
                                onChange={(event) => form.setData('locale', event.target.value || null)}
                            >
                                <option value="">{t('admin.reply_templates.every_locale')}</option>
                                {options.locales.map((locale) => (
                                    <option key={locale} value={locale}>
                                        {locale.toUpperCase()}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <Checkbox
                        checked={form.data.is_internal}
                        onChange={(event) => form.setData('is_internal', event.target.checked)}
                    />
                    {t('admin.reply_templates.is_internal')}
                </label>
                <p className="-mt-2 text-xs text-slate-500">{t('admin.reply_templates.is_internal_help')}</p>

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

/**
 * The tokens this template may use, listed rather than described.
 *
 * Rendered from what the server sent, so a token the renderer does not know
 * cannot be advertised here — which is the failure this sheet exists to
 * prevent, since an unknown token renders as nothing and the desk finds out
 * from a customer.
 */
function PlaceholderSheet({ groups }: { groups: Record<string, string[]> }) {
    const { t } = useTranslations();

    return (
        <details className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <summary className="cursor-pointer text-sm font-medium text-slate-700">
                {t('admin.reply_templates.placeholders')}
            </summary>
            <p className="mt-2 text-xs text-slate-600">{t('admin.reply_templates.placeholders_help')}</p>
            <div className="mt-2 space-y-2">
                {Object.entries(groups).map(([group, tokens]) => (
                    <div key={group}>
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t(`admin.reply_templates.placeholder_groups.${group}`)}
                        </p>
                        <ul className="mt-1 flex flex-wrap gap-1">
                            {tokens.map((token) => (
                                <li
                                    key={token}
                                    className="rounded bg-white px-1.5 py-0.5 font-mono text-xs text-slate-700 ring-1 ring-slate-200"
                                >
                                    {`{{ ${token} }}`}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </details>
    );
}
