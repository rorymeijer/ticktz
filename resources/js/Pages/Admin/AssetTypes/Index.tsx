import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { TranslationsField } from '@/Components/Admin/TranslationsField';
import { IconPlus, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    ButtonLink,
    Card,
    CardBody,
    Checkbox,
    CheckboxGroup,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { AssetTypeAdmin } from '@/types/assets';

type FieldOption = { id: number; key: string; label: string; type: string };

export default function AssetTypesIndex({ types, fields }: { types: AssetTypeAdmin[]; fields: FieldOption[] }) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<AssetTypeAdmin | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<AssetTypeAdmin | null>(null);

    return (
        <AdminLayout
            title={t('assets.admin.title')}
            actions={
                <div className="flex items-center gap-1.5">
                    <ButtonLink href="/admin/assets/import" variant="secondary" size="sm">
                        {t('assets.actions.import')}
                    </ButtonLink>
                    <Button icon={<IconPlus className="h-4 w-4" />} onClick={() => setCreating(true)}>
                        {t('assets.admin.add')}
                    </Button>
                </div>
            }
        >
            <PageHeader title={t('assets.admin.title')} description={t('assets.admin.subtitle')} />

            <Card>
                {types.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {types.map((type) => (
                            <li key={type.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
                                <span
                                    aria-hidden="true"
                                    className="h-8 w-1 shrink-0 rounded-full"
                                    style={{ backgroundColor: type.color }}
                                />

                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-slate-900">{type.name}</span>
                                        {type.tag_prefix ? <Badge tone="slate">{type.tag_prefix}-0001</Badge> : null}
                                        {!type.is_active ? (
                                            <Badge tone="slate">{t('common.labels.inactive')}</Badge>
                                        ) : null}
                                    </span>
                                    {type.description ? (
                                        <span className="mt-0.5 block text-xs text-slate-500">{type.description}</span>
                                    ) : null}
                                    {type.fields.length > 0 ? (
                                        <span className="mt-1 flex flex-wrap gap-1">
                                            {type.fields.map((field) => (
                                                <Badge key={field.id} tone="blue">
                                                    {field.label}
                                                </Badge>
                                            ))}
                                        </span>
                                    ) : null}
                                </span>

                                <span className="text-xs text-slate-500">
                                    {t('assets.admin.asset_count', { count: type.asset_count ?? 0 })}
                                </span>

                                <Button size="sm" variant="ghost" onClick={() => setEditing(type)}>
                                    {t('common.actions.edit')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('common.actions.delete')}
                                    onClick={() => setDeleting(type)}
                                >
                                    <IconTrash className="h-4 w-4 text-slate-500" />
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <EmptyState
                        title={t('assets.admin.empty')}
                        description={t('assets.admin.empty_hint')}
                        action={<Button onClick={() => setCreating(true)}>{t('assets.admin.add')}</Button>}
                    />
                )}
            </Card>

            {creating || editing ? (
                <TypeDialog
                    type={editing}
                    fields={fields}
                    onClose={() => {
                        setCreating(false);
                        setEditing(null);
                    }}
                />
            ) : null}

            <ConfirmDialog
                open={deleting !== null}
                message={t('assets.admin.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(`/admin/asset-types/${deleting.slug}`, {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    });
                }}
            />
        </AdminLayout>
    );
}

function TypeDialog({
    type,
    fields,
    onClose,
}: {
    type: AssetTypeAdmin | null;
    fields: FieldOption[];
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        name: type?.name ?? '',
        name_translations: type?.name_translations ?? {},
        slug: type?.slug ?? '',
        description: type?.description ?? '',
        color: type?.color ?? '#64748b',
        tag_prefix: type?.tag_prefix ?? '',
        is_active: type?.is_active ?? true,
        position: type?.position ?? 100,
        field_ids: type?.field_ids ?? [],
    });

    const submit = () => {
        type
            ? form.put(`/admin/asset-types/${type.slug}`, { preserveScroll: true, onSuccess: onClose })
            : form.post('/admin/asset-types', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={type ? t('assets.admin.edit') : t('assets.admin.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button disabled={form.processing || form.data.name.trim() === ''} onClick={submit}>
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

                                // The prefix follows the name until somebody
                                // has typed one; after that it is theirs. Tags
                                // already handed out carry the old prefix, so
                                // changing it later is deliberate, not a
                                // side-effect of a rename.
                                if (!type && form.data.tag_prefix === '') {
                                    form.setData(
                                        'tag_prefix',
                                        event.target.value
                                            .replace(/[^A-Za-z0-9]/g, '')
                                            .slice(0, 3)
                                            .toUpperCase(),
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

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label={t('assets.admin.tag_prefix')}
                        error={form.errors.tag_prefix}
                        help={t('assets.admin.tag_prefix_help')}
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.tag_prefix ?? ''}
                                maxLength={12}
                                className="font-mono text-xs uppercase"
                                onChange={(event) => form.setData('tag_prefix', event.target.value.toUpperCase())}
                            />
                        )}
                    </Field>

                    <Field label={t('common.labels.type')} error={form.errors.color}>
                        {(props) => (
                            <input
                                {...props}
                                type="color"
                                value={form.data.color}
                                onChange={(event) => form.setData('color', event.target.value)}
                                className="h-9 w-full cursor-pointer rounded-lg border border-slate-300 bg-white p-1"
                            />
                        )}
                    </Field>
                </div>

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description ?? ''}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.admin.fields')} help={t('assets.admin.fields_help')}>
                    {() => (
                        <CheckboxGroup
                            maxHeight="max-h-48"
                            emptyLabel={t('assets.admin.fields_empty')}
                            options={fields.map((field) => ({
                                value: field.id,
                                label: field.label,
                                description: field.key,
                            }))}
                            selected={form.data.field_ids}
                            onChange={(values) => form.setData('field_ids', values as number[])}
                        />
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
