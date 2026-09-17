import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconPlus, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Select,
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

interface FieldOption {
    value: string;
    label: string;
}

interface CustomFieldRow {
    id: number;
    key: string;
    label: string;
    label_translations: Record<string, string>;
    type: string;
    options: FieldOption[];
    placeholder: string | null;
    help_text: string | null;
    default_value: string | null;
    is_required: boolean;
    is_public: boolean;
    validation: { min?: number; max?: number; regex?: string };
    entity: 'ticket' | 'asset';
    is_active: boolean;
    position: number;
    values_count: number;
}

const OPTION_TYPES = ['select', 'multiselect'];

export default function CustomFieldsIndex({ fields, types }: { fields: CustomFieldRow[]; types: string[] }) {
    const { t } = useTranslations();
    const [editing, setEditing] = useState<CustomFieldRow | 'new' | null>(null);
    const [pendingDelete, setPendingDelete] = useState<CustomFieldRow | null>(null);

    return (
        <AdminLayout
            title={t('admin.custom_fields.title')}
            actions={
                <Button size="sm" icon={<IconPlus />} onClick={() => setEditing('new')}>
                    {t('admin.custom_fields.add')}
                </Button>
            }
        >
            <PageHeader title={t('admin.custom_fields.title')} description={t('admin.custom_fields.subtitle')} />

            <Card>
                {fields.length === 0 ? (
                    <EmptyState title={t('admin.custom_fields.empty')} />
                ) : (
                    <Table>
                        <THead>
                            <TR>
                                <TH>{t('admin.custom_fields.columns.field')}</TH>
                                <TH>{t('admin.custom_fields.columns.type')}</TH>
                                <TH>{t('admin.custom_fields.columns.entity')}</TH>
                                <TH className="text-right">{t('admin.custom_fields.columns.values')}</TH>
                                <TH className="text-right">{t('common.labels.actions')}</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {fields.map((field) => (
                                <TR key={field.id}>
                                    <TD>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setEditing(field)}
                                                className="font-medium text-slate-900 hover:text-brand-700"
                                            >
                                                {field.label}
                                            </button>
                                            <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                                                {field.key}
                                            </code>
                                            {field.is_required ? (
                                                <Badge tone="amber">{t('common.labels.required')}</Badge>
                                            ) : null}
                                            {field.is_public ? null : (
                                                <Badge tone="purple">{t('tickets.detail.internal_only')}</Badge>
                                            )}
                                            {field.is_active ? null : (
                                                <Badge tone="red">{t('common.labels.inactive')}</Badge>
                                            )}
                                        </div>
                                    </TD>
                                    <TD className="text-sm text-slate-600">
                                        {t(`admin.custom_fields.types.${field.type}`)}
                                    </TD>
                                    <TD className="text-sm text-slate-600">
                                        {t(`admin.custom_fields.entities.${field.entity}`)}
                                    </TD>
                                    <TD className="text-right tabular-nums">{field.values_count}</TD>
                                    <TD className="space-x-1 text-right">
                                        <Button variant="ghost" size="sm" onClick={() => setEditing(field)}>
                                            {t('common.actions.edit')}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={field.values_count > 0}
                                            aria-label={t('common.actions.delete')}
                                            onClick={() => setPendingDelete(field)}
                                        >
                                            <IconTrash />
                                        </Button>
                                    </TD>
                                </TR>
                            ))}
                        </TBody>
                    </Table>
                )}
            </Card>

            {editing ? (
                <FieldDialog
                    field={editing === 'new' ? null : editing}
                    types={types}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/custom-fields/${pendingDelete.id}`, {
                            preserveScroll: true,
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('common.confirm.delete')}
            />
        </AdminLayout>
    );
}

function FieldDialog({
    field,
    types,
    onClose,
}: {
    field: CustomFieldRow | null;
    types: string[];
    onClose: () => void;
}) {
    const { t, locales } = useTranslations();
    const isEdit = field !== null;
    const locked = (field?.values_count ?? 0) > 0;

    const form = useForm<{
        key: string;
        label: string;
        label_translations: Record<string, string>;
        type: string;
        options: FieldOption[];
        placeholder: string;
        help_text: string;
        default_value: string;
        is_required: boolean;
        is_public: boolean;
        validation: { min?: number | string; max?: number | string; regex?: string };
        entity: 'ticket' | 'asset';
        is_active: boolean;
        position: number;
    }>({
        key: field?.key ?? '',
        label: field?.label ?? '',
        label_translations: field?.label_translations ?? {},
        type: field?.type ?? 'text',
        options: field?.options ?? [],
        placeholder: field?.placeholder ?? '',
        help_text: field?.help_text ?? '',
        default_value: field?.default_value ?? '',
        is_required: field?.is_required ?? false,
        is_public: field?.is_public ?? true,
        validation: field?.validation ?? {},
        entity: field?.entity ?? 'ticket',
        is_active: field?.is_active ?? true,
        position: field?.position ?? 100,
    });

    const keyFromLabel = (label: string) =>
        label
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .replace(/^([0-9])/, 'f$1')
            .slice(0, 64);

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={isEdit ? t('common.actions.edit') : t('admin.custom_fields.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/custom-fields/${field.id}`, { preserveScroll: true, onSuccess: onClose })
                                : form.post('/admin/custom-fields', { preserveScroll: true, onSuccess: onClose })
                        }
                    >
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                {locked ? (
                    <p className="rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800">
                        {t('admin.custom_fields.locked')}
                    </p>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.custom_fields.fields.label')} error={form.errors.label} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.label}
                                autoFocus
                                onChange={(event) => {
                                    form.setData('label', event.target.value);
                                    if (!isEdit) form.setData('key', keyFromLabel(event.target.value));
                                }}
                            />
                        )}
                    </Field>

                    <Field
                        label={t('admin.custom_fields.fields.key')}
                        help={t('admin.custom_fields.fields.key_help')}
                        error={form.errors.key}
                        required
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.key}
                                disabled={locked}
                                className="font-mono"
                                onChange={(event) => form.setData('key', keyFromLabel(event.target.value))}
                            />
                        )}
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('common.labels.type')} error={form.errors.type} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.type}
                                disabled={locked}
                                onChange={(event) => form.setData('type', event.target.value)}
                            >
                                {types.map((type) => (
                                    <option key={type} value={type}>
                                        {t(`admin.custom_fields.types.${type}`)}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label={t('admin.custom_fields.fields.entity')} error={form.errors.entity}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.entity}
                                disabled={locked}
                                onChange={(event) => form.setData('entity', event.target.value as 'ticket' | 'asset')}
                            >
                                <option value="ticket">{t('admin.custom_fields.entities.ticket')}</option>
                                <option value="asset">{t('admin.custom_fields.entities.asset')}</option>
                            </Select>
                        )}
                    </Field>
                </div>

                {OPTION_TYPES.includes(form.data.type) ? (
                    <div>
                        <p className="mb-1.5 text-sm font-medium text-slate-700">
                            {t('admin.custom_fields.fields.options')}
                        </p>
                        <div className="space-y-2">
                            {form.data.options.map((option, index) => (
                                <div key={index} className="flex items-center gap-2">
                                    <TextInput
                                        value={option.value}
                                        aria-label={t('admin.custom_fields.fields.option_value')}
                                        placeholder={t('admin.custom_fields.fields.option_value')}
                                        className="font-mono"
                                        onChange={(event) =>
                                            form.setData(
                                                'options',
                                                form.data.options.map((item, i) =>
                                                    i === index ? { ...item, value: event.target.value } : item,
                                                ),
                                            )
                                        }
                                    />
                                    <TextInput
                                        value={option.label}
                                        aria-label={t('admin.custom_fields.fields.option_label')}
                                        placeholder={t('admin.custom_fields.fields.option_label')}
                                        onChange={(event) =>
                                            form.setData(
                                                'options',
                                                form.data.options.map((item, i) =>
                                                    i === index ? { ...item, label: event.target.value } : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('common.actions.remove')}
                                        onClick={() =>
                                            form.setData(
                                                'options',
                                                form.data.options.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        <IconTrash />
                                    </Button>
                                </div>
                            ))}
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="mt-1.5"
                            icon={<IconPlus />}
                            onClick={() => form.setData('options', [...form.data.options, { value: '', label: '' }])}
                        >
                            {t('admin.custom_fields.fields.add_option')}
                        </Button>
                    </div>
                ) : null}

                <Field label={t('admin.custom_fields.fields.label_translations')}>
                    {() => (
                        <div className="space-y-2">
                            {locales
                                .filter((locale) => locale.code !== 'en')
                                .map((locale) => (
                                    <label key={locale.code} className="flex items-center gap-2">
                                        <span className="w-24 shrink-0 text-xs font-medium text-slate-600">
                                            {locale.native}
                                        </span>
                                        <TextInput
                                            value={form.data.label_translations[locale.code] ?? ''}
                                            onChange={(event) =>
                                                form.setData('label_translations', {
                                                    ...form.data.label_translations,
                                                    [locale.code]: event.target.value,
                                                })
                                            }
                                        />
                                    </label>
                                ))}
                        </div>
                    )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.custom_fields.fields.placeholder')} error={form.errors.placeholder}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.placeholder}
                                onChange={(event) => form.setData('placeholder', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('admin.custom_fields.fields.default_value')} error={form.errors.default_value}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.default_value}
                                onChange={(event) => form.setData('default_value', event.target.value)}
                            />
                        )}
                    </Field>
                </div>

                <Field label={t('admin.custom_fields.fields.help_text')} error={form.errors.help_text}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.help_text}
                            onChange={(event) => form.setData('help_text', event.target.value)}
                        />
                    )}
                </Field>

                <Toggle
                    checked={form.data.is_required}
                    onChange={(value) => form.setData('is_required', value)}
                    label={t('admin.custom_fields.fields.is_required')}
                />

                <Toggle
                    checked={form.data.is_public}
                    onChange={(value) => form.setData('is_public', value)}
                    label={t('admin.custom_fields.fields.is_public')}
                    description={t('admin.custom_fields.fields.is_public_help')}
                />

                <Toggle
                    checked={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                    label={t('admin.custom_fields.fields.is_active')}
                />
            </div>
        </Modal>
    );
}
