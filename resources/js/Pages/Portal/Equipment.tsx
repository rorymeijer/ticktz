import PortalLayout from '@/Layouts/PortalLayout';
import { AssetStatusBadge, AssetTypeBadge, WarrantyBadge } from '@/Components/Assets/AssetBadges';
import { Card, CardBody, EmptyState, Pagination } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDate } from '@/lib/datetime';
import type { Paginated } from '@/types';
import type { PortalAsset } from '@/types/assets';

/**
 * "What have I got?"
 *
 * Exists because half the tickets a desk gets about a machine open with the
 * requester not knowing what the machine is called. The tag is the biggest
 * thing on each card for exactly that reason.
 */
export default function PortalEquipment({ assets }: { assets: Paginated<PortalAsset> }) {
    const { t, locale } = useTranslations();

    return (
        <PortalLayout title={t('assets.portal_title')}>
            <div className="mb-6">
                <h2 className="text-2xl font-semibold tracking-tight text-slate-900">{t('assets.portal_title')}</h2>
                <p className="mt-1.5 max-w-2xl text-sm text-slate-600">{t('assets.portal_subtitle')}</p>
            </div>

            {assets.data.length > 0 ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {assets.data.map((asset) => (
                            <Card key={asset.id}>
                                <CardBody>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <AssetTypeBadge type={asset.type} />
                                        <AssetStatusBadge status={asset.status} />
                                        <WarrantyBadge expired={asset.warranty_expired} />
                                    </div>

                                    <p className="mt-2 font-mono text-lg font-semibold tracking-tight text-slate-900">
                                        {asset.asset_tag}
                                    </p>
                                    <p className="mt-0.5 text-sm text-slate-700">{asset.name}</p>

                                    <dl className="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                        {asset.serial_number ? (
                                            <div className="flex gap-2">
                                                <dt className="w-28 shrink-0">{t('assets.fields.serial_number')}</dt>
                                                <dd className="font-mono">{asset.serial_number}</dd>
                                            </div>
                                        ) : null}
                                        {asset.location ? (
                                            <div className="flex gap-2">
                                                <dt className="w-28 shrink-0">{t('assets.fields.location')}</dt>
                                                <dd>{asset.location}</dd>
                                            </div>
                                        ) : null}
                                        {asset.warranty_ends_at ? (
                                            <div className="flex gap-2">
                                                <dt className="w-28 shrink-0">{t('assets.fields.warranty_ends_at')}</dt>
                                                <dd>{formatDate(asset.warranty_ends_at, locale)}</dd>
                                            </div>
                                        ) : null}
                                        {/* Only ever set on a colleague's equipment — your own
                                            says nothing, because you know whose it is. */}
                                        {asset.assignee ? (
                                            <div className="flex gap-2">
                                                <dt className="w-28 shrink-0">{t('assets.fields.assigned_to')}</dt>
                                                <dd>{asset.assignee.name}</dd>
                                            </div>
                                        ) : null}
                                    </dl>
                                </CardBody>
                            </Card>
                        ))}
                    </div>

                    <Pagination page={assets} className="mt-4 rounded-xl border border-slate-200 bg-white" />
                </>
            ) : (
                <Card>
                    <EmptyState title={t('assets.portal_empty')} />
                </Card>
            )}
        </PortalLayout>
    );
}
