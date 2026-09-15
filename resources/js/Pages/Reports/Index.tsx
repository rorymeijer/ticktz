import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { BarList, Legend, StackedBars, StatTile } from '@/Components/Charts/Primitives';
import { LineChart } from '@/Components/Charts/LineChart';
import { SERIES, STATUS, duration } from '@/Components/Charts/tokens';
import { IconDownload, IconTrash } from '@/Components/Icons';
import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Checkbox,
    ConfirmDialog,
    Dropdown,
    Field,
    Modal,
    Select,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import type { ReportData, ReportFilters, ReportOptions, SavedReport } from '@/types/reports';

export default function ReportsIndex({
    data,
    filters,
    options,
    saved,
    can,
}: {
    data: ReportData;
    filters: ReportFilters;
    options: ReportOptions;
    saved: SavedReport[];
    can: { manage: boolean };
}) {
    const { t } = useTranslations();
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState<SavedReport | null>(null);

    const go = (patch: Partial<ReportFilters>) =>
        router.get('/reports', { ...filters, ...patch }, { preserveState: true, replace: true, preserveScroll: true });

    const exportUrl = (subject: 'summary' | 'tickets') =>
        `/reports/export?report=${filters.report}&from=${filters.from}&to=${filters.to}` +
        `&dimension=${filters.dimension}&subject=${subject}`;

    return (
        <AppLayout
            title={t('reports.title')}
            header={t('reports.title')}
            actions={
                <Dropdown
                    trigger={
                        <Button size="sm" variant="secondary" icon={<IconDownload className="h-4 w-4" />}>
                            {t('reports.export.label')}
                        </Button>
                    }
                >
                    {/* Plain anchors, not Inertia links: these are downloads,
                        and an Inertia visit to a streamed CSV gets a page it
                        cannot render instead of a file. */}
                    <a
                        href={exportUrl('summary')}
                        className="block px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    >
                        {t('reports.export.summary')}
                    </a>
                    <a
                        href={exportUrl('tickets')}
                        className="block px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                    >
                        {t('reports.export.tickets')}
                    </a>
                </Dropdown>
            }
        >
            <div className="mb-5">
                <h2 className="text-xl font-semibold tracking-tight text-slate-900">
                    {t(`reports.reports.${filters.report}`)}
                </h2>
                <p className="mt-1 max-w-2xl text-sm text-slate-500">{t(`reports.descriptions.${filters.report}`)}</p>
            </div>

            {/* Filters in one row above the charts, so the controls that change
                every plot are in one place rather than beside each one. */}
            <div className="mb-4 flex flex-wrap items-end gap-2">
                <div className="flex flex-wrap gap-1.5">
                    {options.reports.map((report) => (
                        <button
                            key={report}
                            type="button"
                            onClick={() => go({ report })}
                            aria-current={filters.report === report ? 'page' : undefined}
                            className={cn(
                                'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                                filters.report === report
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-white text-slate-600 shadow-card hover:text-slate-900',
                            )}
                        >
                            {t(`reports.reports.${report}`)}
                        </button>
                    ))}
                </div>

                <div className="ml-auto flex flex-wrap items-end gap-2">
                    <Preset days={7} label={t('reports.filters.presets.week')} onPick={go} />
                    <Preset days={30} label={t('reports.filters.presets.month')} onPick={go} />
                    <Preset days={90} label={t('reports.filters.presets.quarter')} onPick={go} />

                    <label className="flex items-center gap-1.5 text-xs text-slate-500">
                        {t('reports.filters.from')}
                        <TextInput
                            type="date"
                            value={filters.from}
                            onChange={(event) => go({ from: event.target.value })}
                            className="h-9 w-36 text-sm"
                        />
                    </label>
                    <label className="flex items-center gap-1.5 text-xs text-slate-500">
                        {t('reports.filters.to')}
                        <TextInput
                            type="date"
                            value={filters.to}
                            onChange={(event) => go({ to: event.target.value })}
                            className="h-9 w-36 text-sm"
                        />
                    </label>

                    {filters.report !== 'workload' ? (
                        <label className="flex items-center gap-1.5 text-xs text-slate-500">
                            {t('reports.filters.dimension')}
                            <Select
                                value={filters.dimension}
                                onChange={(event) => go({ dimension: event.target.value })}
                                className="h-9 w-40 text-sm"
                            >
                                {options.dimensions.map((dimension) => (
                                    <option key={dimension} value={dimension}>
                                        {t(`reports.dimensions.${dimension}`)}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    ) : null}
                </div>
            </div>

            {filters.report === 'sla' ? <SlaReport data={data} filters={filters} /> : null}
            {filters.report === 'volume' ? <VolumeReport data={data} filters={filters} /> : null}
            {filters.report === 'workload' ? <WorkloadReport data={data} /> : null}
            {filters.report === 'cycle_time' ? <CycleTimeReport data={data} filters={filters} /> : null}

            <Card className="mt-5">
                <CardHeader
                    title={t('reports.saved.title')}
                    actions={
                        <Button size="sm" variant="ghost" onClick={() => setSaving(true)}>
                            {t('reports.saved.save')}
                        </Button>
                    }
                />
                {saved.length > 0 ? (
                    <ul className="divide-y divide-slate-100">
                        {saved.map((report) => (
                            <li key={report.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5">
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.get('/reports', {
                                            report: report.report,
                                            ...report.filters,
                                        })
                                    }
                                    className="min-w-0 flex-1 text-left"
                                >
                                    <span className="block text-sm font-medium text-slate-800">{report.name}</span>
                                    <span className="mt-0.5 block text-xs text-slate-400">
                                        {t(`reports.reports.${report.report}`)}
                                        {report.description ? ` · ${report.description}` : ''}
                                    </span>
                                </button>
                                {report.is_shared ? (
                                    <span className="text-xs text-slate-400">{t('reports.saved.shared')}</span>
                                ) : null}
                                {report.is_mine || can.manage ? (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label={t('common.actions.delete')}
                                        onClick={() => setDeleting(report)}
                                    >
                                        <IconTrash className="h-4 w-4 text-slate-400" />
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <CardBody className="text-sm text-slate-500">
                        {t('reports.saved.empty')} {t('reports.saved.empty_hint')}
                    </CardBody>
                )}
            </Card>

            {saving ? <SaveDialog filters={filters} canShare={can.manage} onClose={() => setSaving(false)} /> : null}

            <ConfirmDialog
                open={deleting !== null}
                message={t('reports.saved.confirm_delete')}
                confirmLabel={t('common.actions.delete')}
                onCancel={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(`/reports/saved/${deleting.id}`, {
                        preserveScroll: true,
                        onFinish: () => setDeleting(null),
                    });
                }}
            />
        </AppLayout>
    );
}

function Preset({
    days,
    label,
    onPick,
}: {
    days: number;
    label: string;
    onPick: (patch: Partial<ReportFilters>) => void;
}) {
    const today = new Date();
    const from = new Date(today);
    from.setDate(from.getDate() - (days - 1));

    const iso = (date: Date) => date.toISOString().slice(0, 10);

    return (
        <Button size="sm" variant="ghost" onClick={() => onPick({ from: iso(from), to: iso(today) })}>
            {label}
        </Button>
    );
}

// -----------------------------------------------------------------
// The four reports
// -----------------------------------------------------------------

function SlaReport({ data, filters }: { data: ReportData; filters: ReportFilters }) {
    const { t } = useTranslations();
    const series = data.slaSeries;

    // The two clocks are summed per day for the chart: the question it answers
    // is "how did we do", and the split between them is in the tiles above.
    const combined = (series?.resolution ?? []).map((day, index) => ({
        date: day.date,
        met: day.met + (series?.first_response?.[index]?.met ?? 0),
        breached: day.breached + (series?.first_response?.[index]?.breached ?? 0),
    }));

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label={t('reports.sla.compliance')}
                    value={data.summary?.compliance !== null ? `${data.summary?.compliance}%` : '—'}
                    tone={
                        data.summary?.compliance === null || data.summary?.compliance === undefined
                            ? 'default'
                            : data.summary.compliance >= 90
                              ? 'good'
                              : 'bad'
                    }
                    hint={t('reports.sla.outcomes') + `: ${(data.summary?.met ?? 0) + (data.summary?.breached ?? 0)}`}
                />
                <StatTile label={t('reports.sla.met')} value={data.summary?.met ?? 0} />
                <StatTile label={t('reports.sla.breached')} value={data.summary?.breached ?? 0} />
                <StatTile
                    label={t('reports.sla.first_response')}
                    value={
                        data.by_metric?.first_response.compliance !== null
                            ? `${data.by_metric?.first_response.compliance}%`
                            : '—'
                    }
                    hint={
                        t('reports.sla.resolution') +
                        ': ' +
                        (data.by_metric?.resolution.compliance !== null
                            ? `${data.by_metric?.resolution.compliance}%`
                            : '—')
                    }
                />
            </div>

            <Card className="mt-4">
                <CardHeader title={t('reports.sla.per_day')} description={t('reports.sla.note')} />
                <CardBody>
                    <Legend
                        items={[
                            { label: t('reports.sla.met'), color: STATUS.met },
                            { label: t('reports.sla.breached'), color: STATUS.breached },
                        ]}
                    />
                    <div className="mt-3">
                        <StackedBars days={combined} />
                    </div>
                </CardBody>
            </Card>

            {data.breakdown && data.breakdown.length > 0 ? (
                <Card className="mt-4">
                    <CardHeader
                        title={t('reports.sla.breakdown', {
                            dimension: t(`reports.dimensions.${filters.dimension}`).toLowerCase(),
                        })}
                    />
                    <CardBody>
                        <BarList
                            color={STATUS.met}
                            max={100}
                            rows={data.breakdown.map((row) => ({
                                id: row.id,
                                label: row.label,
                                value: row.compliance ?? 0,
                                meta: `${row.met}/${row.total}`,
                            }))}
                            format={(value) => `${value}%`}
                        />
                    </CardBody>
                </Card>
            ) : null}
        </>
    );
}

function VolumeReport({ data, filters }: { data: ReportData; filters: ReportFilters }) {
    const { t } = useTranslations();
    const series = data.series ?? [];

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label={t('reports.volume.created')} value={data.summary?.created ?? 0} />
                <StatTile label={t('reports.volume.resolved')} value={data.summary?.resolved ?? 0} />
                <StatTile label={t('reports.volume.reopened')} value={data.summary?.reopened ?? 0} />
                <StatTile
                    label={t('reports.volume.clearance')}
                    value={data.summary?.clearance !== null ? `${data.summary?.clearance}%` : '—'}
                    hint={t('reports.volume.clearance_hint')}
                    tone={
                        data.summary?.clearance === null || data.summary?.clearance === undefined
                            ? 'default'
                            : data.summary.clearance >= 100
                              ? 'good'
                              : 'default'
                    }
                />
            </div>

            <Card className="mt-4">
                <CardHeader title={t('reports.volume.per_day')} />
                <CardBody>
                    <Legend
                        items={[
                            { label: t('reports.volume.created'), color: SERIES[0] },
                            { label: t('reports.volume.resolved'), color: SERIES[1] },
                        ]}
                    />
                    <div className="mt-3">
                        <LineChart
                            labels={series.map((day) => day.date)}
                            series={[
                                {
                                    key: 'created',
                                    label: t('reports.volume.created'),
                                    color: SERIES[0],
                                    values: series.map((day) => day.created),
                                },
                                {
                                    key: 'resolved',
                                    label: t('reports.volume.resolved'),
                                    color: SERIES[1],
                                    values: series.map((day) => day.resolved),
                                },
                            ]}
                            format={(value) => String(Math.round(value))}
                        />
                    </div>
                </CardBody>
            </Card>

            {data.breakdown && data.breakdown.length > 0 ? (
                <Card className="mt-4">
                    <CardHeader
                        title={t('reports.volume.breakdown', {
                            dimension: t(`reports.dimensions.${filters.dimension}`).toLowerCase(),
                        })}
                    />
                    <CardBody>
                        <BarList
                            color={SERIES[0]}
                            rows={data.breakdown.map((row) => ({
                                id: row.id,
                                label: row.label,
                                value: row.count ?? 0,
                            }))}
                        />
                    </CardBody>
                </Card>
            ) : null}
        </>
    );
}

function WorkloadReport({ data }: { data: ReportData }) {
    const { t } = useTranslations();

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <StatTile label={t('reports.workload.resolved')} value={data.summary?.resolved ?? 0} />
                <StatTile label={t('reports.workload.agents')} value={data.summary?.agents ?? 0} />
            </div>

            <Card className="mt-4">
                <CardHeader title={t('reports.workload.per_agent')} description={t('reports.workload.note')} />
                <CardBody>
                    <BarList
                        color={SERIES[0]}
                        rows={(data.rows ?? []).map((row) => ({
                            id: row.id,
                            label: row.label,
                            value: row.resolved,
                            meta: duration(row.average_seconds),
                        }))}
                    />
                </CardBody>
            </Card>
        </>
    );
}

/**
 * Two charts, never one with two y-axes.
 *
 * A first response is measured in minutes and a resolution in days; putting
 * them on one plot means choosing an arbitrary alignment between two scales,
 * which invents a relationship that is not in the data.
 */
function CycleTimeReport({ data, filters }: { data: ReportData; filters: ReportFilters }) {
    const { t } = useTranslations();
    const series = data.series ?? [];
    const labels = series.map((day) => day.date);

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-3">
                <StatTile
                    label={t('reports.cycle_time.first_response')}
                    value={duration(data.summary?.first_response)}
                />
                <StatTile label={t('reports.cycle_time.resolution')} value={duration(data.summary?.resolution)} />
                <StatTile label={t('reports.cycle_time.measured')} value={data.summary?.measured ?? 0} />
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title={t('reports.cycle_time.response_per_day')}
                        description={t('reports.cycle_time.note')}
                    />
                    <CardBody>
                        <LineChart
                            labels={labels}
                            series={[
                                {
                                    key: 'first_response',
                                    label: t('reports.cycle_time.first_response'),
                                    color: SERIES[0],
                                    values: series.map((day) => day.first_response),
                                },
                            ]}
                            format={duration}
                        />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('reports.cycle_time.resolution_per_day')} />
                    <CardBody>
                        <LineChart
                            labels={labels}
                            series={[
                                {
                                    key: 'resolution',
                                    label: t('reports.cycle_time.resolution'),
                                    color: SERIES[1],
                                    values: series.map((day) => day.resolution),
                                },
                            ]}
                            format={duration}
                        />
                    </CardBody>
                </Card>
            </div>

            {data.breakdown && data.breakdown.length > 0 ? (
                <Card className="mt-4">
                    <CardHeader
                        title={t('reports.cycle_time.breakdown', {
                            dimension: t(`reports.dimensions.${filters.dimension}`).toLowerCase(),
                        })}
                    />
                    <CardBody>
                        <BarList
                            color={SERIES[1]}
                            rows={data.breakdown.map((row) => ({
                                id: row.id,
                                label: row.label,
                                value: row.average_seconds ?? 0,
                                meta: `${row.count}`,
                            }))}
                            format={duration}
                        />
                    </CardBody>
                </Card>
            ) : null}
        </>
    );
}

function SaveDialog({
    filters,
    canShare,
    onClose,
}: {
    filters: ReportFilters;
    canShare: boolean;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const form = useForm({
        name: '',
        description: '',
        report: filters.report,
        filters: { from: filters.from, to: filters.to, dimension: filters.dimension },
        is_shared: false,
    });

    return (
        <Modal
            open
            onClose={onClose}
            title={t('reports.saved.save')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={form.processing}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing || form.data.name.trim() === ''}
                        onClick={() => form.post('/reports/saved', { preserveScroll: true, onSuccess: onClose })}
                    >
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label={t('reports.saved.name')} error={form.errors.name} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('reports.saved.description')} error={form.errors.description}>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    )}
                </Field>

                {canShare ? (
                    <div>
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <Checkbox
                                checked={form.data.is_shared}
                                onChange={(event) => form.setData('is_shared', event.target.checked)}
                            />
                            {t('reports.saved.shared')}
                        </label>
                        <p className="mt-1 text-xs text-slate-500">{t('reports.saved.shared_help')}</p>
                    </div>
                ) : null}
            </div>
        </Modal>
    );
}
