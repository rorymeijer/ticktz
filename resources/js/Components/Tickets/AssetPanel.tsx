import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { AssetStatusBadge } from '@/Components/Assets/AssetBadges';
import { IconSearch, IconServer, IconX } from '@/Components/Icons';
import { Button, Card, CardBody, CardHeader, Modal, TextInput } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { AssetSummary } from '@/types/assets';

/**
 * The equipment a ticket is about.
 *
 * This is the half of a CMDB that earns its keep. A register nothing points at
 * answers "what do we own"; one attached to tickets answers "what keeps
 * breaking", which is the question that changes a purchasing decision.
 */
export function AssetPanel({
    ticketKey,
    requesterId,
    assets,
    canLink,
}: {
    ticketKey: string;
    requesterId: number | null;
    assets: AssetSummary[];
    canLink: boolean;
}) {
    const { t } = useTranslations();
    const [picking, setPicking] = useState(false);

    return (
        <Card>
            <CardHeader
                title={t('assets.ticket.title')}
                actions={
                    canLink ? (
                        <Button size="sm" variant="ghost" onClick={() => setPicking(true)}>
                            {t('assets.ticket.add')}
                        </Button>
                    ) : null
                }
            />

            {assets.length > 0 ? (
                <ul className="divide-y divide-slate-100">
                    {assets.map((asset) => (
                        <li key={asset.id} className="flex items-start gap-2 px-5 py-2.5">
                            <IconServer className="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-300" />
                            <span className="min-w-0 flex-1">
                                <Link
                                    href={`/agent/assets/${asset.asset_tag}`}
                                    className="block text-sm font-medium text-slate-800 hover:text-brand-700"
                                >
                                    {asset.name}
                                </Link>
                                <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <span className="font-mono text-[11px] text-slate-500">{asset.asset_tag}</span>
                                    <AssetStatusBadge status={asset.status} />
                                </span>
                            </span>
                            {canLink ? (
                                <button
                                    type="button"
                                    aria-label={t('assets.actions.unlink')}
                                    onClick={() =>
                                        router.delete(`/agent/tickets/${ticketKey}/assets/${asset.asset_tag}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="rounded p-1 text-slate-500 hover:text-red-600"
                                >
                                    <IconX className="h-3.5 w-3.5" />
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : (
                <CardBody className="text-sm text-slate-500">{t('assets.ticket.empty')}</CardBody>
            )}

            {picking ? (
                <LinkDialog
                    ticketKey={ticketKey}
                    requesterId={requesterId}
                    linked={assets.map((asset) => asset.id)}
                    onClose={() => setPicking(false)}
                />
            ) : null}
        </Card>
    );
}

/**
 * Search-and-link, seeded from the requester's own equipment.
 *
 * A ticket about a broken laptop is almost always about *their* laptop, so the
 * box opens with an answer rather than a cursor.
 */
function LinkDialog({
    ticketKey,
    requesterId,
    linked,
    onClose,
}: {
    ticketKey: string;
    requesterId: number | null;
    linked: number[];
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<AssetSummary[]>([]);

    useEffect(() => {
        const controller = new AbortController();
        const query = new URLSearchParams();

        if (term.trim() !== '') query.set('q', term.trim());
        if (requesterId) query.set('requester', String(requesterId));

        const timeout = setTimeout(() => {
            fetch(`/agent/assets/suggest?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => response.json())
                .then((data: { assets: AssetSummary[] }) =>
                    setResults(data.assets.filter((asset) => !linked.includes(asset.id))),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term, requesterId]);

    return (
        <Modal open onClose={onClose} size="lg" title={t('assets.ticket.add')}>
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

            {term.trim() === '' && results.length > 0 ? (
                <p className="mt-2 text-xs text-slate-500">{t('assets.ticket.suggested')}</p>
            ) : null}

            <div className="mt-3 max-h-80 overflow-y-auto">
                {results.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {results.map((asset) => (
                            <li key={asset.id} className="flex items-center gap-3 py-2.5">
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium text-slate-800">{asset.name}</span>
                                    <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                        <span className="font-mono">{asset.asset_tag}</span>
                                        {asset.serial_number ? <span>· {asset.serial_number}</span> : null}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(
                                            `/agent/tickets/${ticketKey}/assets`,
                                            { asset_id: asset.id },
                                            { preserveScroll: true, onSuccess: onClose },
                                        )
                                    }
                                >
                                    {t('assets.actions.link')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="py-6 text-center text-sm text-slate-500">{t('common.empty.title')}</p>
                )}
            </div>
        </Modal>
    );
}
