import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { tintedChip } from '@/lib/contrast';
import { IconPlus, IconTrash } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    ConfirmDialog,
    Field,
    Modal,
    PageHeader,
    Select,
    TextInput,
    Toggle,
} from '@/Components/UI';
import { TranslationsField } from '@/Components/Admin/TranslationsField';
import { useTranslations } from '@/hooks/useTranslations';
import { slugify } from '@/lib/slug';

interface StatusRow {
    id: number;
    name: string;
    name_translations: Record<string, string>;
    slug: string;
    category: string;
    color: string;
    description: string | null;
    pauses_sla: boolean;
    is_public: boolean;
    is_system: boolean;
    position: number;
    ticket_count: number;
}

interface PriorityRow {
    id: number;
    name: string;
    name_translations: Record<string, string>;
    slug: string;
    level: number;
    color: string;
    is_default: boolean;
    is_public: boolean;
    ticket_count: number;
}

interface LabelRow {
    id: number;
    name: string;
    name_translations: Record<string, string>;
    slug: string;
    color: string;
    description: string | null;
    ticket_count: number;
}

const CATEGORIES = ['new', 'open', 'pending', 'resolved', 'closed'] as const;

export default function ServiceDeskIndex({
    statuses,
    priorities,
    labels,
}: {
    statuses: StatusRow[];
    priorities: PriorityRow[];
    labels: LabelRow[];
}) {
    const { t } = useTranslations();
    const [editingStatus, setEditingStatus] = useState<StatusRow | 'new' | null>(null);
    const [editingPriority, setEditingPriority] = useState<PriorityRow | 'new' | null>(null);
    const [editingLabel, setEditingLabel] = useState<LabelRow | 'new' | null>(null);
    const [pendingDelete, setPendingDelete] = useState<{ url: string; message: string } | null>(null);

    return (
        <AdminLayout title={t('admin.service_desk.title')}>
            <PageHeader title={t('admin.service_desk.title')} description={t('admin.service_desk.subtitle')} />

            <div className="space-y-5">
                <Card>
                    <CardHeader
                        title={t('admin.service_desk.statuses.title')}
                        description={t('admin.service_desk.statuses.description')}
                        actions={
                            <Button size="sm" icon={<IconPlus />} onClick={() => setEditingStatus('new')}>
                                {t('admin.service_desk.statuses.add')}
                            </Button>
                        }
                    />
                    <ul className="divide-y divide-slate-100">
                        {statuses.map((status) => (
                            <li key={status.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5">
                                <span
                                    aria-hidden="true"
                                    className="h-3 w-3 shrink-0 rounded-full"
                                    style={{ backgroundColor: status.color }}
                                />
                                <span className="font-medium text-slate-900">{status.name}</span>
                                <Badge tone="slate">{t(`tickets.status_category.${status.category}`)}</Badge>
                                {status.pauses_sla ? (
                                    <Badge tone="amber">{t('admin.service_desk.statuses.pauses_sla')}</Badge>
                                ) : null}
                                {status.is_public ? null : <Badge tone="purple">{t('common.labels.system')}</Badge>}

                                <span className="ml-auto text-xs text-slate-500">
                                    {t('admin.service_desk.ticket_count', { count: status.ticket_count })}
                                </span>

                                <Button size="sm" variant="ghost" onClick={() => setEditingStatus(status)}>
                                    {t('common.actions.edit')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    disabled={status.is_system}
                                    aria-label={t('common.actions.delete')}
                                    onClick={() =>
                                        setPendingDelete({
                                            url: `/admin/statuses/${status.id}`,
                                            message: t('common.confirm.delete'),
                                        })
                                    }
                                >
                                    <IconTrash />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card>
                    <CardHeader
                        title={t('admin.service_desk.priorities.title')}
                        description={t('admin.service_desk.priorities.description')}
                        actions={
                            <Button size="sm" icon={<IconPlus />} onClick={() => setEditingPriority('new')}>
                                {t('admin.service_desk.priorities.add')}
                            </Button>
                        }
                    />
                    <ul className="divide-y divide-slate-100">
                        {priorities.map((priority) => (
                            <li key={priority.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5">
                                <span
                                    aria-hidden="true"
                                    className="h-3 w-3 shrink-0 rounded-full"
                                    style={{ backgroundColor: priority.color }}
                                />
                                <span className="font-medium text-slate-900">{priority.name}</span>
                                <span className="text-xs text-slate-500">
                                    {t('admin.service_desk.priorities.level')} {priority.level}
                                </span>
                                {priority.is_default ? <Badge tone="green">{t('common.labels.default')}</Badge> : null}

                                <span className="ml-auto text-xs text-slate-500">
                                    {t('admin.service_desk.ticket_count', { count: priority.ticket_count })}
                                </span>

                                <Button size="sm" variant="ghost" onClick={() => setEditingPriority(priority)}>
                                    {t('common.actions.edit')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={t('common.actions.delete')}
                                    onClick={() =>
                                        setPendingDelete({
                                            url: `/admin/priorities/${priority.id}`,
                                            message: t('common.confirm.delete'),
                                        })
                                    }
                                >
                                    <IconTrash />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card>
                    <CardHeader
                        title={t('admin.service_desk.labels.title')}
                        description={t('admin.service_desk.labels.description')}
                        actions={
                            <Button size="sm" icon={<IconPlus />} onClick={() => setEditingLabel('new')}>
                                {t('admin.service_desk.labels.add')}
                            </Button>
                        }
                    />
                    <CardBody>
                        {labels.length === 0 ? (
                            <p className="text-sm text-slate-500">{t('common.empty.title')}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-2">
                                {labels.map((label) => (
                                    <li key={label.id} className="flex items-center gap-1">
                                        <button
                                            type="button"
                                            onClick={() => setEditingLabel(label)}
                                            className="rounded-full px-2.5 py-1 text-xs font-medium"
                                            style={tintedChip(label.color)}
                                        >
                                            {label.name}
                                            <span className="ml-1.5 opacity-60">{label.ticket_count}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </div>

            {editingStatus ? (
                <StatusDialog
                    status={editingStatus === 'new' ? null : editingStatus}
                    onClose={() => setEditingStatus(null)}
                />
            ) : null}

            {editingPriority ? (
                <PriorityDialog
                    priority={editingPriority === 'new' ? null : editingPriority}
                    onClose={() => setEditingPriority(null)}
                />
            ) : null}

            {editingLabel ? (
                <LabelDialog
                    label={editingLabel === 'new' ? null : editingLabel}
                    onClose={() => setEditingLabel(null)}
                />
            ) : null}

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(pendingDelete.url, {
                            preserveScroll: true,
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={pendingDelete?.message ?? ''}
            />
        </AdminLayout>
    );
}

function ColorField({ value, onChange, label }: { value: string; onChange: (value: string) => void; label: string }) {
    return (
        <Field label={label}>
            {(props) => (
                <div className="flex items-center gap-2">
                    <input
                        type="color"
                        aria-label={label}
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                        className="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-white p-1"
                    />
                    <TextInput {...props} value={value} className="font-mono" onChange={(event) => onChange(event.target.value)} />
                </div>
            )}
        </Field>
    );
}

function StatusDialog({ status, onClose }: { status: StatusRow | null; onClose: () => void }) {
    const { t } = useTranslations();
    const isEdit = status !== null;

    const form = useForm({
        name: status?.name ?? '',
        name_translations: status?.name_translations ?? {},
        slug: status?.slug ?? '',
        category: status?.category ?? 'open',
        color: status?.color ?? '#64748b',
        description: status?.description ?? '',
        pauses_sla: status?.pauses_sla ?? false,
        is_public: status?.is_public ?? true,
        position: status?.position ?? 100,
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={isEdit ? t('common.actions.edit') : t('admin.service_desk.statuses.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/statuses/${status.id}`, { preserveScroll: true, onSuccess: onClose })
                                : form.post('/admin/statuses', { preserveScroll: true, onSuccess: onClose })
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
                            disabled={status?.is_system}
                            className="font-mono"
                            onChange={(event) => form.setData('slug', slugify(event.target.value))}
                        />
                    )}
                </Field>

                <TranslationsField
                    label={t('admin.custom_fields.fields.label_translations')}
                    values={form.data.name_translations}
                    onChange={(values) => form.setData('name_translations', values)}
                />

                <Field label={t('admin.service_desk.statuses.category')} error={form.errors.category} required>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.category}
                            disabled={status?.is_system}
                            onChange={(event) => form.setData('category', event.target.value)}
                        >
                            {CATEGORIES.map((category) => (
                                <option key={category} value={category}>
                                    {t(`tickets.status_category.${category}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <ColorField
                    label={t('admin.settings.fields.primary_color')}
                    value={form.data.color}
                    onChange={(value) => form.setData('color', value)}
                />

                <Toggle
                    checked={form.data.pauses_sla}
                    onChange={(value) => form.setData('pauses_sla', value)}
                    label={t('admin.service_desk.statuses.pauses_sla')}
                />

                <Toggle
                    checked={form.data.is_public}
                    onChange={(value) => form.setData('is_public', value)}
                    label={t('admin.service_desk.statuses.is_public')}
                />
            </div>
        </Modal>
    );
}

function PriorityDialog({ priority, onClose }: { priority: PriorityRow | null; onClose: () => void }) {
    const { t } = useTranslations();
    const isEdit = priority !== null;

    const form = useForm({
        name: priority?.name ?? '',
        name_translations: priority?.name_translations ?? {},
        slug: priority?.slug ?? '',
        level: priority?.level ?? 3,
        color: priority?.color ?? '#2563eb',
        is_default: priority?.is_default ?? false,
        is_public: priority?.is_public ?? true,
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={isEdit ? t('common.actions.edit') : t('admin.service_desk.priorities.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/priorities/${priority.id}`, { preserveScroll: true, onSuccess: onClose })
                                : form.post('/admin/priorities', { preserveScroll: true, onSuccess: onClose })
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

                <TranslationsField
                    label={t('admin.custom_fields.fields.label_translations')}
                    values={form.data.name_translations}
                    onChange={(values) => form.setData('name_translations', values)}
                />

                <Field label={t('admin.service_desk.priorities.level')} error={form.errors.level} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="number"
                            min={1}
                            max={9}
                            value={form.data.level}
                            onChange={(event) => form.setData('level', Number(event.target.value))}
                        />
                    )}
                </Field>

                <ColorField
                    label={t('admin.settings.fields.primary_color')}
                    value={form.data.color}
                    onChange={(value) => form.setData('color', value)}
                />

                <Toggle
                    checked={form.data.is_default}
                    onChange={(value) => form.setData('is_default', value)}
                    label={t('admin.service_desk.priorities.is_default')}
                />

                <Toggle
                    checked={form.data.is_public}
                    onChange={(value) => form.setData('is_public', value)}
                    label={t('admin.service_desk.priorities.is_public')}
                />
            </div>
        </Modal>
    );
}

function LabelDialog({ label, onClose }: { label: LabelRow | null; onClose: () => void }) {
    const { t } = useTranslations();
    const isEdit = label !== null;

    const form = useForm({
        name: label?.name ?? '',
        name_translations: label?.name_translations ?? {},
        slug: label?.slug ?? '',
        color: label?.color ?? '#64748b',
        description: label?.description ?? '',
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={isEdit ? t('common.actions.edit') : t('admin.service_desk.labels.add')}
            footer={
                <>
                    {isEdit ? (
                        <Button
                            variant="danger"
                            className="mr-auto"
                            onClick={() =>
                                router.delete(`/admin/labels/${label.id}`, {
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
                                ? form.put(`/admin/labels/${label.id}`, { preserveScroll: true, onSuccess: onClose })
                                : form.post('/admin/labels', { preserveScroll: true, onSuccess: onClose })
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

                <TranslationsField
                    label={t('admin.custom_fields.fields.label_translations')}
                    values={form.data.name_translations}
                    onChange={(values) => form.setData('name_translations', values)}
                />

                <ColorField
                    label={t('admin.settings.fields.primary_color')}
                    value={form.data.color}
                    onChange={(value) => form.setData('color', value)}
                />

                <Field label={t('common.labels.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>
            </div>
        </Modal>
    );
}
