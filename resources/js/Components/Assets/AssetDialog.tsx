import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button, Field, Modal, Select, TextInput, Textarea } from '@/Components/UI';
import { PersonPicker } from '@/Components/UI/PersonPicker';
import { useTranslations } from '@/hooks/useTranslations';
import type { AssetDetail, AssetOptions } from '@/types/assets';
import type { UserSummary } from '@/types/tickets';
import { RichTextField } from '@/Components/RichText/RichTextField';

/**
 * Adding or editing an asset.
 *
 * Deliberately one dialog for both: the fields are identical, and a separate
 * "create" page that drifts out of step with the edit form is the way this
 * kind of screen usually rots.
 */
export function AssetDialog({
    asset,
    options,
    onClose,
}: {
    asset: AssetDetail | null;
    options: AssetOptions;
    onClose: () => void;
}) {
    const { t } = useTranslations();

    const form = useForm({
        asset_type_id: asset?.type?.id ? String(asset.type.id) : String(options.types[0]?.id ?? ''),
        asset_tag: asset?.asset_tag ?? '',
        name: asset?.name ?? '',
        serial_number: asset?.serial_number ?? '',
        manufacturer: asset?.manufacturer ?? '',
        model: asset?.model ?? '',
        status: asset?.status ?? 'in_stock',
        location: asset?.location ?? '',
        organization_id: asset?.organization?.id ? String(asset.organization.id) : '',
        team_id: asset?.team?.id ? String(asset.team.id) : '',
        assigned_to: asset?.assignee?.id ? String(asset.assignee.id) : '',
        purchased_at: asset?.purchased_at ?? '',
        warranty_ends_at: asset?.warranty_ends_at ?? '',
        notes: asset?.notes ?? '',
    });

    // Who holds the asset. The field has always been on the server —
    // `assigned_to` is validated by AssetController and shown read-only on the
    // asset page — and there has never been anywhere to set it. Until now the
    // only way to say who has a laptop was the API or an import.
    const [holder, setHolder] = useState<UserSummary | null>(asset?.assignee ?? null);

    const submit = () => {
        asset
            ? form.put(`/agent/assets/${asset.asset_tag}`, { preserveScroll: true, onSuccess: onClose })
            : form.post('/agent/assets');
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={asset ? t('assets.actions.edit') : t('assets.actions.add')}
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
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('assets.fields.name')} error={form.errors.name} required className="sm:col-span-2">
                    {(props) => (
                        <TextInput
                            {...props}
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.type')} error={form.errors.asset_type_id} required>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.asset_type_id}
                            onChange={(event) => form.setData('asset_type_id', event.target.value)}
                        >
                            {options.types.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field
                    label={t('assets.fields.asset_tag')}
                    error={form.errors.asset_tag}
                    help={asset ? undefined : t('assets.fields.asset_tag_help')}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.asset_tag}
                            className="font-mono text-xs"
                            onChange={(event) => form.setData('asset_tag', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.status')} error={form.errors.status}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value as typeof form.data.status)}
                        >
                            {options.statuses.map((status) => (
                                <option key={status} value={status}>
                                    {t(`assets.status.${status}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('assets.fields.location')} error={form.errors.location}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.location ?? ''}
                            onChange={(event) => form.setData('location', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.serial_number')} error={form.errors.serial_number}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.serial_number ?? ''}
                            className="font-mono text-xs"
                            onChange={(event) => form.setData('serial_number', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.manufacturer')} error={form.errors.manufacturer}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.manufacturer ?? ''}
                            onChange={(event) => form.setData('manufacturer', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.model')} error={form.errors.model}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.model ?? ''}
                            onChange={(event) => form.setData('model', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.organization')} error={form.errors.organization_id}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.organization_id}
                            onChange={(event) => form.setData('organization_id', event.target.value)}
                        >
                            <option value="">{t('common.labels.none')}</option>
                            {options.organizations.map((organization) => (
                                <option key={organization.id} value={organization.id}>
                                    {organization.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('assets.fields.team')} error={form.errors.team_id}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.team_id}
                            onChange={(event) => form.setData('team_id', event.target.value)}
                        >
                            <option value="">{t('common.labels.none')}</option>
                            {options.teams.map((team) => (
                                <option key={team.id} value={team.id}>
                                    {team.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <Field label={t('assets.fields.assigned_to')} error={form.errors.assigned_to}>
                    {(props) => (
                        <PersonPicker
                            {...props}
                            value={holder}
                            allowNobody
                            // Anybody with an account: a laptop belongs to
                            // whoever uses it, and most of those people are
                            // customers rather than agents.
                            query={{ scope: 'user' }}
                            placeholder={t('common.labels.none')}
                            onChange={(person) => {
                                setHolder(person);
                                form.setData('assigned_to', person ? String(person.id) : '');
                            }}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.purchased_at')} error={form.errors.purchased_at}>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="date"
                            value={form.data.purchased_at ?? ''}
                            onChange={(event) => form.setData('purchased_at', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('assets.fields.warranty_ends_at')} error={form.errors.warranty_ends_at}>
                    {(props) => (
                        <TextInput
                            {...props}
                            type="date"
                            value={form.data.warranty_ends_at ?? ''}
                            onChange={(event) => form.setData('warranty_ends_at', event.target.value)}
                        />
                    )}
                </Field>

                <RichTextField
                    label={t('assets.fields.notes')}
                    error={form.errors.notes}
                    className="sm:col-span-2"
                    value={form.data.notes ?? ''}
                    onChange={(html) => form.setData('notes', html)}
                    minHeight="6rem"
                />
            </div>
        </Modal>
    );
}
