import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { AssetStatusBadge, AssetTypeBadge, WarrantyBadge } from '@/Components/Assets/AssetBadges';
import { FilterSelect } from '@/Components/Admin/FilterBar';
import { IconPlus, IconSearch } from '@/Components/Icons';
import {
    Button,
    ButtonLink,
    Card,
    EmptyState,
    Pagination,
    Table,
    TBody,
    TD,
    TextInput,
    TH,
    THead,
    TR,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDate } from '@/lib/datetime';
import { AssetDialog } from '@/Components/Assets/AssetDialog';
import type { Paginated } from '@/types';
import type { AssetOptions, AssetSummary } from '@/types/assets';

export default function AssetsIndex({
    assets,
    filters,
    options,
    can,
}: {
    assets: Paginated<AssetSummary>;
    filters: Record<string, string | number | null>;
    options: AssetOptions;
    can: { manage: boolean };
}) {
    const { t, locale } = useTranslations();
    const [adding, setAdding] = useState(false);

    const query: Record<string, string | undefined> = {
        q: (filters.q as string) ?? undefined,
        type: (filters.type as string) ?? undefined,
        status: (filters.status as string) ?? undefined,
        organization: filters.organization ? String(filters.organization) : undefined,
    };

    return (
        <AppLayout
            title={t('assets.title')}
            header={t('assets.title')}
            actions={
                can.manage ? (
                    <div className="flex items-center gap-1.5">
                        <ButtonLink href="/admin/assets/import" variant="secondary" size="sm">
                            {t('assets.actions.import')}
                        </ButtonLink>
                        <Button size="sm" icon={<IconPlus className="h-4 w-4" />} onClick={() => setAdding(true)}>
                            {t('assets.actions.add')}
                        </Button>
                    </div>
                ) : null
            }
        >
            <Card>
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
                    <SearchField value={(filters.q as string) ?? ''} query={query} />

                    <FilterSelect
                        url="/agent/assets"
                        filters={query}
                        name="type"
                        label={t('assets.filters.any_type')}
                        options={options.types.map((type) => ({ value: type.slug, label: type.name }))}
                    />

                    <FilterSelect
                        url="/agent/assets"
                        filters={query}
                        name="status"
                        label={t('assets.filters.any_status')}
                        options={options.statuses.map((status) => ({
                            value: status,
                            label: t(`assets.status.${status}`),
                        }))}
                    />

                    <FilterSelect
                        url="/agent/assets"
                        filters={query}
                        name="organization"
                        label={t('assets.filters.any_organization')}
                        options={options.organizations.map((organization) => ({
                            value: String(organization.id),
                            label: organization.name,
                        }))}
                    />
                </div>

                {assets.data.length > 0 ? (
                    <>
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('assets.fields.asset_tag')}</TH>
                                    <TH>{t('assets.fields.name')}</TH>
                                    <TH>{t('assets.fields.status')}</TH>
                                    <TH>{t('assets.fields.assigned_to')}</TH>
                                    <TH>{t('assets.fields.location')}</TH>
                                    <TH>{t('assets.fields.warranty_ends_at')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {assets.data.map((asset) => (
                                    <TR key={asset.id}>
                                        <TD>
                                            <Link
                                                href={`/agent/assets/${asset.asset_tag}`}
                                                className="font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                                            >
                                                {asset.asset_tag}
                                            </Link>
                                        </TD>
                                        <TD>
                                            <span className="font-medium text-slate-900">{asset.name}</span>
                                            <span className="mt-0.5 block">
                                                <AssetTypeBadge type={asset.type} />
                                            </span>
                                        </TD>
                                        <TD>
                                            <AssetStatusBadge status={asset.status} />
                                        </TD>
                                        <TD className="text-slate-600">
                                            {asset.assignee?.name ?? (
                                                <span className="text-slate-300">{t('assets.fields.unassigned')}</span>
                                            )}
                                        </TD>
                                        <TD className="text-slate-500">{asset.location ?? '—'}</TD>
                                        <TD>
                                            {asset.warranty_ends_at ? (
                                                <span className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                                    {formatDate(asset.warranty_ends_at, locale)}
                                                    <WarrantyBadge expired={asset.warranty_expired} />
                                                </span>
                                            ) : (
                                                <span className="text-slate-300">—</span>
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                        <Pagination page={assets} />
                    </>
                ) : (
                    <EmptyState
                        title={filters.q ? t('common.empty.title') : t('assets.empty')}
                        description={filters.q ? t('common.empty.description') : t('assets.empty_hint')}
                        action={
                            can.manage && !filters.q ? (
                                <Button onClick={() => setAdding(true)}>{t('assets.actions.add')}</Button>
                            ) : null
                        }
                    />
                )}
            </Card>

            {adding ? <AssetDialog asset={null} options={options} onClose={() => setAdding(false)} /> : null}
        </AppLayout>
    );
}

/**
 * Submitted rather than debounced. An asset search is usually a tag read off a
 * sticker — typed once, in full, then Enter — and a request per keystroke on a
 * register of thousands is a lot of work for nobody's benefit.
 */
function SearchField({ value, query }: { value: string; query: Record<string, string | undefined> }) {
    const { t } = useTranslations();
    const [term, setTerm] = useState(value);

    return (
        <form
            className="relative min-w-56 flex-1"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(
                    '/agent/assets',
                    { ...query, q: term || undefined },
                    { preserveState: true, replace: true, preserveScroll: true },
                );
            }}
        >
            <IconSearch className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <TextInput
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder={t('assets.search.placeholder')}
                aria-label={t('assets.search.placeholder')}
                className="pl-9"
            />
        </form>
    );
}
