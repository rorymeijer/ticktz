import { useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconAlert, IconCheckCircle } from '@/Components/Icons';
import { Badge, Button, Card, CardBody, CardHeader, Field, PageHeader, Select } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { SharedProps } from '@/types';
import type { AssetTypeSummary, ImportResult } from '@/types/assets';

/**
 * Two steps, always: the upload reports what would happen, and only an
 * explicit second click writes anything. Nobody has ever undone four hundred
 * imported rows by hand.
 */
export default function AssetImport({
    types,
    columns,
    maxRows,
    result,
    pending,
}: {
    types: AssetTypeSummary[];
    columns: string[];
    maxRows: number;
    result: ImportResult | null;
    pending: boolean;
}) {
    const { t } = useTranslations();
    const { errors: pageErrors } = usePage<SharedProps>().props;
    const fileInput = useRef<HTMLInputElement>(null);

    const upload = useForm<{ file: File | null; asset_type_id: string }>({
        file: null,
        asset_type_id: '',
    });

    const confirm = useForm({});

    const totalRows = (result?.create ?? 0) + (result?.update ?? 0);

    return (
        <AdminLayout title={t('assets.import.title')}>
            <PageHeader title={t('assets.import.title')} description={t('assets.import.subtitle')} />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0 space-y-4">
                    <Card>
                        <CardBody className="space-y-4">
                            <Field
                                label={t('assets.import.file')}
                                error={upload.errors.file ?? pageErrors.file}
                                help={t('assets.import.file_help', { max: maxRows })}
                                required
                            >
                                {(props) => (
                                    <input
                                        {...props}
                                        ref={fileInput}
                                        type="file"
                                        accept=".csv,text/csv,text/plain"
                                        onChange={(event) => upload.setData('file', event.target.files?.[0] ?? null)}
                                        className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                                    />
                                )}
                            </Field>

                            <Field label={t('assets.import.default_type')} error={upload.errors.asset_type_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={upload.data.asset_type_id}
                                        onChange={(event) => upload.setData('asset_type_id', event.target.value)}
                                    >
                                        <option value="">{t('assets.import.default_type_none')}</option>
                                        {types.map((type) => (
                                            <option key={type.id} value={type.id}>
                                                {type.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Button
                                disabled={upload.processing || upload.data.file === null}
                                onClick={() =>
                                    upload.post('/admin/assets/import/preview', {
                                        forceFormData: true,
                                        preserveScroll: true,
                                    })
                                }
                            >
                                {t('assets.import.preview')}
                            </Button>
                        </CardBody>
                    </Card>

                    {result ? (
                        <Card>
                            <CardHeader
                                title={
                                    result.stage === 'done'
                                        ? t('assets.import.done', {
                                              created: result.created ?? 0,
                                              updated: result.updated ?? 0,
                                          })
                                        : t('assets.import.preview')
                                }
                            />
                            <CardBody className="space-y-4">
                                {result.stage === 'preview' ? (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge tone="green">
                                            {t('assets.import.will_create', { count: result.create ?? 0 })}
                                        </Badge>
                                        <Badge tone="blue">
                                            {t('assets.import.will_update', { count: result.update ?? 0 })}
                                        </Badge>
                                        {result.errors.length > 0 ? (
                                            <Badge tone="red">
                                                <IconAlert className="h-3 w-3" />
                                                {t('assets.import.has_errors', { count: result.errors.length })}
                                            </Badge>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="flex items-center gap-2 text-sm text-emerald-700">
                                        <IconCheckCircle className="h-4 w-4" />
                                        {t('assets.import.done', {
                                            created: result.created ?? 0,
                                            updated: result.updated ?? 0,
                                        })}
                                    </p>
                                )}

                                {result.errors.length > 0 ? (
                                    <div className="rounded-lg bg-red-50 p-3">
                                        <p className="text-xs font-medium text-red-800">
                                            {t('assets.import.errors_note')}
                                        </p>
                                        <ul className="mt-1.5 space-y-0.5">
                                            {result.errors.slice(0, 25).map((error) => (
                                                <li key={error.line} className="text-xs text-red-700">
                                                    <span className="font-mono">
                                                        {t('assets.import.line', { line: error.line })}
                                                    </span>{' '}
                                                    — {error.message}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : null}

                                {result.preview && result.preview.length > 0 ? (
                                    <ul className="divide-y divide-slate-100 text-sm">
                                        {result.preview.map((row) => (
                                            <li key={row.line} className="flex flex-wrap items-center gap-2 py-1.5">
                                                <Badge tone={row.action === 'create' ? 'green' : 'blue'}>
                                                    {t(`assets.import.action.${row.action}`)}
                                                </Badge>
                                                <span className="font-mono text-xs text-slate-500">
                                                    {row.asset_tag ?? '—'}
                                                </span>
                                                <span className="min-w-0 flex-1 truncate text-slate-800">
                                                    {row.name}
                                                </span>
                                                <span className="text-xs text-slate-400">{row.type}</span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}

                                {result.stage === 'preview' && pending && totalRows > 0 ? (
                                    <Button
                                        size="lg"
                                        disabled={confirm.processing}
                                        onClick={() => confirm.post('/admin/assets/import', { preserveScroll: true })}
                                    >
                                        {t('assets.import.confirm', { count: totalRows })}
                                    </Button>
                                ) : null}
                            </CardBody>
                        </Card>
                    ) : null}
                </div>

                <Card>
                    <CardHeader title={t('assets.import.columns')} />
                    <CardBody>
                        <ul className="flex flex-wrap gap-1">
                            {columns.map((column) => (
                                <li key={column}>
                                    <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">
                                        {column}
                                    </code>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-xs leading-5 text-slate-500">{t('assets.import.columns_help')}</p>
                        <p className="mt-2 text-xs leading-5 text-slate-500">{t('assets.import.matching')}</p>
                    </CardBody>
                </Card>
            </div>
        </AdminLayout>
    );
}
