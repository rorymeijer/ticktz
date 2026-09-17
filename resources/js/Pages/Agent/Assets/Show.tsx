import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { AssetDialog } from '@/Components/Assets/AssetDialog';
import { AssetStatusBadge, AssetTypeBadge, WarrantyBadge } from '@/Components/Assets/AssetBadges';
import { StatusBadge } from '@/Components/Tickets/Badges';
import { IconLink, IconSearch, IconTicket, IconTrash, IconX } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    Select,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDate } from '@/lib/datetime';
import type { AssetDetail, AssetOptions, AssetRelationView, AssetSummary, AssetTicket } from '@/types/assets';

export default function AssetShow({
    asset,
    tickets,
    options,
    can,
}: {
    asset: AssetDetail;
    tickets: AssetTicket[];
    options: AssetOptions;
    can: { manage: boolean };
}) {
    const { t, locale } = useTranslations();
    const [editing, setEditing] = useState(false);
    const [relating, setRelating] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [unrelating, setUnrelating] = useState<AssetRelationView | null>(null);

    return (
        <AppLayout
            title={`${asset.asset_tag} — ${asset.name}`}
            header={
                <span className="flex items-center gap-2">
                    <Link href="/agent/assets" className="text-slate-500 hover:text-slate-600">
                        {t('assets.title')}
                    </Link>
                    <span aria-hidden="true" className="text-slate-300">
                        /
                    </span>
                    <span className="font-mono">{asset.asset_tag}</span>
                </span>
            }
            actions={
                can.manage ? (
                    <div className="flex items-center gap-1.5">
                        <Button size="sm" variant="secondary" onClick={() => setEditing(true)}>
                            {t('common.actions.edit')}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label={t('common.actions.delete')}
                            onClick={() => setDeleting(true)}
                        >
                            <IconTrash className="h-4 w-4 text-slate-500" />
                        </Button>
                    </div>
                ) : null
            }
        >
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-4">
                    <Card>
                        <CardBody>
                            <div className="flex flex-wrap items-center gap-2">
                                <AssetStatusBadge status={asset.status} />
                                <AssetTypeBadge type={asset.type} />
                                <WarrantyBadge expired={asset.warranty_expired} />
                            </div>

                            <h2 className="mt-2.5 text-lg font-semibold tracking-tight text-slate-900">{asset.name}</h2>
                            <p className="mt-0.5 font-mono text-xs text-slate-500">{asset.asset_tag}</p>

                            <dl className="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
                                <Detail label={t('assets.fields.serial_number')} value={asset.serial_number} mono />
                                <Detail label={t('assets.fields.manufacturer')} value={asset.manufacturer} />
                                <Detail label={t('assets.fields.model')} value={asset.model} />
                                <Detail label={t('assets.fields.location')} value={asset.location} />
                                <Detail
                                    label={t('assets.fields.assigned_to')}
                                    value={asset.assignee?.name ?? t('assets.fields.unassigned')}
                                />
                                <Detail label={t('assets.fields.organization')} value={asset.organization?.name} />
                                <Detail label={t('assets.fields.team')} value={asset.team?.name} />
                                <Detail
                                    label={t('assets.fields.purchased_at')}
                                    value={asset.purchased_at ? formatDate(asset.purchased_at, locale) : null}
                                />
                                <Detail
                                    label={t('assets.fields.warranty_ends_at')}
                                    value={asset.warranty_ends_at ? formatDate(asset.warranty_ends_at, locale) : null}
                                />
                                {asset.purchase_cost !== null ? (
                                    <Detail
                                        label={t('assets.fields.purchase_cost')}
                                        value={`${asset.currency ?? '€'} ${(asset.purchase_cost / 100).toFixed(2)}`}
                                    />
                                ) : null}
                            </dl>

                            {asset.notes ? (
                                <div className="mt-4 whitespace-pre-wrap border-t border-slate-100 pt-4 text-sm leading-6 text-slate-700">
                                    {asset.notes}
                                </div>
                            ) : null}
                        </CardBody>
                    </Card>

                    {asset.fields.length > 0 ? (
                        <Card>
                            <CardHeader title={t('assets.sections.attributes')} />
                            <CardBody>
                                <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                    {asset.fields.map((field) => (
                                        <Detail key={field.key} label={field.label} value={field.display} />
                                    ))}
                                </dl>
                            </CardBody>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader title={t('assets.sections.tickets')} />
                        {tickets.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                {tickets.map((ticket) => (
                                    <li key={ticket.key}>
                                        <Link
                                            href={`/agent/tickets/${ticket.key}`}
                                            className="flex flex-wrap items-center gap-3 px-5 py-3 hover:bg-slate-50"
                                        >
                                            <IconTicket className="h-3.5 w-3.5 shrink-0 text-slate-300" />
                                            <span className="font-mono text-xs font-semibold text-brand-600">
                                                {ticket.key}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate text-sm text-slate-800">
                                                {ticket.subject}
                                            </span>
                                            <StatusBadge status={ticket.status} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <CardBody className="text-sm text-slate-500">{t('assets.sections.tickets_empty')}</CardBody>
                        )}
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card>
                        <CardHeader
                            title={t('assets.sections.relations')}
                            actions={
                                can.manage ? (
                                    <Button size="sm" variant="ghost" onClick={() => setRelating(true)}>
                                        {t('assets.actions.relate')}
                                    </Button>
                                ) : null
                            }
                        />
                        {asset.relations.length > 0 ? (
                            <ul className="divide-y divide-slate-100">
                                {asset.relations.map((relation) => (
                                    <li key={`${relation.id}-${relation.direction}`} className="px-5 py-3">
                                        <div className="flex items-start gap-2">
                                            <IconLink className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
                                            <div className="min-w-0 flex-1">
                                                {/* The label is derived from the single stored
                                                    row, so the two ends of a relationship can
                                                    never contradict each other. */}
                                                <p className="text-[11px] uppercase tracking-wide text-slate-500">
                                                    {t(`assets.relation.${relation.type}`)}
                                                </p>
                                                <Link
                                                    href={`/agent/assets/${relation.asset.asset_tag}`}
                                                    className="mt-0.5 block text-sm font-medium text-slate-800 hover:text-brand-700"
                                                >
                                                    {relation.asset.name}
                                                </Link>
                                                <p className="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-[11px] text-slate-500">
                                                    {relation.asset.asset_tag}
                                                </p>
                                                {relation.note ? (
                                                    <p className="mt-0.5 text-xs italic text-slate-500">
                                                        {relation.note}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {can.manage ? (
                                                <button
                                                    type="button"
                                                    aria-label={t('assets.actions.unrelate')}
                                                    onClick={() => setUnrelating(relation)}
                                                    className="rounded p-1 text-slate-500 hover:text-red-600"
                                                >
                                                    <IconX className="h-3.5 w-3.5" />
                                                </button>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <CardBody className="text-sm text-slate-500">
                                {t('assets.sections.relations_empty')}
                            </CardBody>
                        )}
                    </Card>
                </div>
            </div>

            {editing ? <AssetDialog asset={asset} options={options} onClose={() => setEditing(false)} /> : null}

            {relating ? <RelateDialog asset={asset} options={options} onClose={() => setRelating(false)} /> : null}

            <ConfirmDialog
                open={deleting}
                message={t('assets.confirm.delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(false)}
                onConfirm={() => router.delete(`/agent/assets/${asset.asset_tag}`)}
            />

            <ConfirmDialog
                open={unrelating !== null}
                message={t('assets.confirm.unrelate')}
                confirmLabel={t('assets.actions.unrelate')}
                onCancel={() => setUnrelating(null)}
                onConfirm={() => {
                    if (!unrelating) return;
                    router.delete(`/agent/assets/${asset.asset_tag}/relations/${unrelating.id}`, {
                        preserveScroll: true,
                        onFinish: () => setUnrelating(null),
                    });
                }}
            />
        </AppLayout>
    );
}

function Detail({ label, value, mono = false }: { label: string; value?: string | null; mono?: boolean }) {
    if (!value) {
        return null;
    }

    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className={mono ? 'mt-0.5 font-mono text-xs text-slate-800' : 'mt-0.5 text-sm text-slate-800'}>
                {value}
            </dd>
        </div>
    );
}

/**
 * Connecting two assets. The register is searched as you type rather than the
 * whole of it being shipped to the browser — a CMDB worth having outgrows a
 * select box on its first import.
 */
function RelateDialog({ asset, options, onClose }: { asset: AssetDetail; options: AssetOptions; onClose: () => void }) {
    const { t } = useTranslations();
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<AssetSummary[]>([]);
    const form = useForm({ related_asset_id: 0, type: options.relation_types?.[0] ?? 'connected_to', note: '' });

    useEffect(() => {
        if (term.trim().length < 2) {
            setResults([]);

            return;
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            fetch(`/agent/assets/suggest?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: { assets: AssetSummary[] }) =>
                    // Never offer the asset its own page is showing.
                    setResults(data.assets.filter((candidate) => candidate.id !== asset.id)),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [term, asset.id]);

    const chosen = results.find((candidate) => candidate.id === form.data.related_asset_id);

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={t('assets.actions.relate')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || !form.data.related_asset_id}
                        onClick={() =>
                            form.post(`/agent/assets/${asset.asset_tag}/relations`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        {t('assets.actions.link')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('assets.relation.connected_to')} error={form.errors.type}>
                    {(props) => (
                        <Select
                            {...props}
                            value={form.data.type}
                            onChange={(event) => form.setData('type', event.target.value as typeof form.data.type)}
                        >
                            {(options.relation_types ?? []).map((type) => (
                                <option key={type} value={type}>
                                    {t(`assets.relation.${type}`)}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>

                <div>
                    <div className="relative">
                        <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
                        <TextInput
                            type="search"
                            autoFocus
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder={t('assets.ticket.search')}
                            aria-label={t('assets.ticket.search')}
                            className="pl-9"
                        />
                    </div>

                    {chosen ? (
                        <p className="mt-2 flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
                            <span className="font-mono text-xs">{chosen.asset_tag}</span>
                            <span className="min-w-0 flex-1 truncate">{chosen.name}</span>
                            <button
                                type="button"
                                aria-label={t('common.actions.clear')}
                                onClick={() => form.setData('related_asset_id', 0)}
                                className="text-brand-400 hover:text-brand-700"
                            >
                                <IconX className="h-3.5 w-3.5" />
                            </button>
                        </p>
                    ) : results.length > 0 ? (
                        <ul className="mt-2 max-h-56 divide-y divide-slate-100 overflow-y-auto">
                            {results.map((candidate) => (
                                <li key={candidate.id}>
                                    <button
                                        type="button"
                                        onClick={() => form.setData('related_asset_id', candidate.id)}
                                        className="flex w-full items-center gap-2 py-2 text-left text-sm hover:bg-slate-50"
                                    >
                                        <span className="font-mono text-xs text-slate-500">{candidate.asset_tag}</span>
                                        <span className="min-w-0 flex-1 truncate text-slate-800">{candidate.name}</span>
                                        <Badge tone="slate">{candidate.type?.name}</Badge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>

                <Field label={t('common.labels.description')} error={form.errors.note}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.note}
                            maxLength={255}
                            onChange={(event) => form.setData('note', event.target.value)}
                        />
                    )}
                </Field>
            </div>
        </Modal>
    );
}
