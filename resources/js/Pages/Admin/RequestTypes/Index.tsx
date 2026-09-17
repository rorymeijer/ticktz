import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconPlus, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardHeader,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TextInput,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';

interface CategoryRow {
    id: number;
    name: string;
    name_translations: Record<string, string>;
    slug: string;
    description: string | null;
    icon: string | null;
    color: string;
    is_active: boolean;
    position: number;
}

interface RequestTypeRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    category: { id: number; name: string } | null;
    visibility: 'everyone' | 'organizations' | 'agents';
    is_active: boolean;
    fields_count: number;
    ticket_count: number;
}

export default function RequestTypesIndex({
    categories,
    requestTypes,
}: {
    categories: CategoryRow[];
    requestTypes: RequestTypeRow[];
}) {
    const { t } = useTranslations();
    const [editingCategory, setEditingCategory] = useState<CategoryRow | 'new' | null>(null);
    const [pendingDelete, setPendingDelete] = useState<RequestTypeRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.request_types.title')}
            actions={
                <ButtonLink href="/admin/request-types/create" size="sm" icon={<IconPlus />}>
                    {t('admin.request_types.create')}
                </ButtonLink>
            }
        >
            <PageHeader title={t('admin.request_types.title')} description={t('admin.request_types.subtitle')} />

            <div className="space-y-5">
                <Card>
                    {requestTypes.length === 0 ? (
                        <EmptyState
                            title={t('admin.request_types.empty')}
                            action={
                                <ButtonLink href="/admin/request-types/create">
                                    {t('admin.request_types.create')}
                                </ButtonLink>
                            }
                        />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.request_types.columns.request_type')}</TH>
                                    <TH>{t('admin.request_types.columns.category')}</TH>
                                    <TH>{t('admin.request_types.columns.visibility')}</TH>
                                    <TH className="text-right">{t('admin.request_types.columns.fields')}</TH>
                                    <TH className="text-right">{t('admin.request_types.columns.tickets')}</TH>
                                    <TH className="text-right">{t('common.labels.actions')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {requestTypes.map((type) => (
                                    <TR key={type.id}>
                                        <TD>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={`/admin/request-types/${type.id}/edit`}
                                                    className="font-medium text-slate-900 hover:text-brand-700"
                                                >
                                                    {type.name}
                                                </Link>
                                                {type.is_active ? null : (
                                                    <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                                )}
                                            </div>
                                            {type.description ? (
                                                <p className="mt-0.5 text-xs text-slate-500">{type.description}</p>
                                            ) : null}
                                        </TD>
                                        <TD className="text-sm text-slate-600">{type.category?.name ?? '—'}</TD>
                                        <TD>
                                            <Badge tone={type.visibility === 'everyone' ? 'green' : 'purple'}>
                                                {t(`admin.request_types.visibility.${type.visibility}`)}
                                            </Badge>
                                        </TD>
                                        <TD className="text-right tabular-nums">{type.fields_count}</TD>
                                        <TD className="text-right tabular-nums">{type.ticket_count}</TD>
                                        <TD className="space-x-1 text-right">
                                            <ButtonLink
                                                href={`/admin/request-types/${type.id}/edit`}
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {t('common.actions.edit')}
                                            </ButtonLink>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={type.ticket_count > 0}
                                                onClick={() => setPendingDelete(type)}
                                            >
                                                {t('common.actions.delete')}
                                            </Button>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>

                <Card>
                    <CardHeader
                        title={t('admin.request_types.categories.title')}
                        description={t('admin.request_types.categories.description')}
                        actions={
                            <Button size="sm" icon={<IconPlus />} onClick={() => setEditingCategory('new')}>
                                {t('admin.request_types.categories.add')}
                            </Button>
                        }
                    />
                    <CardBody>
                        {categories.length === 0 ? (
                            <p className="text-sm text-slate-500">{t('admin.request_types.categories.empty')}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-2">
                                {categories.map((category) => (
                                    <li key={category.id}>
                                        <button
                                            type="button"
                                            onClick={() => setEditingCategory(category)}
                                            className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-700 hover:border-brand-300"
                                        >
                                            <span
                                                aria-hidden="true"
                                                className="h-2.5 w-2.5 rounded-full"
                                                style={{ backgroundColor: category.color }}
                                            />
                                            {category.name}
                                            {category.is_active ? null : (
                                                <span className="text-xs text-red-600">
                                                    ({t('common.labels.inactive')})
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </div>

            {editingCategory ? (
                <CategoryDialog
                    category={editingCategory === 'new' ? null : editingCategory}
                    onClose={() => setEditingCategory(null)}
                />
            ) : null}

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/request-types/${pendingDelete.id}`, {
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.request_types.confirm_delete')}
            />
        </AdminLayout>
    );
}

function CategoryDialog({ category, onClose }: { category: CategoryRow | null; onClose: () => void }) {
    const { t, locales } = useTranslations();
    const isEdit = category !== null;

    const form = useForm({
        name: category?.name ?? '',
        name_translations: category?.name_translations ?? {},
        slug: category?.slug ?? '',
        description: category?.description ?? '',
        icon: category?.icon ?? '',
        color: category?.color ?? '#4f46e5',
        is_active: category?.is_active ?? true,
        position: category?.position ?? 100,
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={isEdit ? t('common.actions.edit') : t('admin.request_types.categories.add')}
            footer={
                <>
                    {isEdit ? (
                        <Button
                            variant="danger"
                            className="mr-auto"
                            onClick={() =>
                                router.delete(`/admin/portal-categories/${category.id}`, {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                })
                            }
                        >
                            {t('common.actions.delete')}
                        </Button>
                    ) : null}
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/portal-categories/${category.id}`, {
                                      preserveScroll: true,
                                      onSuccess: onClose,
                                  })
                                : form.post('/admin/portal-categories', {
                                      preserveScroll: true,
                                      onSuccess: onClose,
                                  })
                        }
                    >
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
                            value={form.data.name}
                            autoFocus
                            onChange={(event) => {
                                form.setData('name', event.target.value);
                                if (!isEdit) form.setData('slug', slugify(event.target.value));
                            }}
                        />
                    )}
                </Field>

                <Field label={t('common.labels.key')} error={form.errors.slug} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.slug}
                            className="font-mono"
                            onChange={(event) => form.setData('slug', slugify(event.target.value))}
                        />
                    )}
                </Field>

                {locales
                    .filter((locale) => locale.code !== 'en')
                    .map((locale) => (
                        <Field key={locale.code} label={`${t('common.labels.name')} (${locale.native})`}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.name_translations[locale.code] ?? ''}
                                    onChange={(event) =>
                                        form.setData('name_translations', {
                                            ...form.data.name_translations,
                                            [locale.code]: event.target.value,
                                        })
                                    }
                                />
                            )}
                        </Field>
                    ))}

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('admin.settings.fields.primary_color')} error={form.errors.color} required>
                    {(props) => (
                        <div className="flex items-center gap-2">
                            <input
                                type="color"
                                aria-label={t('admin.settings.fields.primary_color')}
                                value={form.data.color}
                                onChange={(event) => form.setData('color', event.target.value)}
                                className="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-white p-1"
                            />
                            <TextInput
                                {...props}
                                value={form.data.color}
                                className="font-mono"
                                onChange={(event) => form.setData('color', event.target.value)}
                            />
                        </div>
                    )}
                </Field>

                <Toggle
                    checked={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                    label={t('common.labels.active')}
                />
            </div>
        </Modal>
    );
}
