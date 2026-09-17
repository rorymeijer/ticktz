import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { Badge, Button, Card, CardBody, CardHeader, ConfirmDialog, EmptyState, PageHeader } from '@/Components/UI';
import { ReleaseNotes } from '@/lib/releaseNotes';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';
import { cn } from '@/lib/cn';

interface ReadinessCheck {
    key: string;
    ok: boolean;
    blocking: boolean;
    detail: string | null;
}

interface AvailableRelease {
    version: string;
    name: string;
    notes: string;
    url: string;
    published_at: string | null;
    is_pre_release: boolean;
    step: 'patch' | 'minor' | 'major' | 'unknown';
}

interface UpgradeRow {
    id: number;
    from_version: string;
    to_version: string;
    status: 'queued' | 'running' | 'installed' | 'failed';
    log: string | null;
    error: string | null;
    finished: boolean;
    abandoned: boolean;
    requester: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

interface Props {
    current: string;
    checkEnabled: boolean;
    repository: string;
    release: AvailableRelease | null;
    checkedAt: string | null;
    canSelfUpgrade: boolean;
    checks: ReadinessCheck[];
    upgrade: UpgradeRow | null;
    history: UpgradeRow[];
}

const STATUS_TONE = {
    queued: 'slate',
    running: 'blue',
    installed: 'green',
    failed: 'red',
} as const;

export default function UpdatesIndex({
    current,
    checkEnabled,
    repository,
    release,
    checkedAt,
    canSelfUpgrade,
    checks,
    upgrade,
    history,
}: Props) {
    const { t } = useTranslations();
    const [confirming, setConfirming] = useState(false);
    const [live, setLive] = useState<UpgradeRow | null>(upgrade);
    const form = useForm({});

    const running = live !== null && !live.finished;

    /*
     * Poll while an upgrade is moving.
     *
     * Not an Inertia reload: for part of an upgrade the application on disk is
     * being replaced, and a full page render is the request least likely to
     * survive that. A failed poll is expected rather than exceptional — it
     * means the swap is happening — so it is swallowed and tried again.
     */
    useEffect(() => {
        if (!running) return;

        let cancelled = false;

        const timer = window.setInterval(async () => {
            try {
                const response = await fetch('/admin/updates/status', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) return;

                const data = (await response.json()) as { upgrade: UpgradeRow | null };

                if (!cancelled) setLive(data.upgrade);
            } catch {
                // The application is being replaced under us. Try again.
            }
        }, 2000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [running]);

    // Once it is done, bring the rest of the page back in step with it.
    const wasRunning = useRef(running);
    useEffect(() => {
        if (wasRunning.current && !running && live?.status === 'installed') {
            router.reload();
        }
        wasRunning.current = running;
    }, [running, live?.status]);

    const install = () => {
        setConfirming(false);
        form.post('/admin/updates', { preserveScroll: true, onSuccess: () => router.reload({ only: ['upgrade'] }) });
    };

    return (
        <AdminLayout title={t('admin.updates.title')}>
            <PageHeader title={t('admin.updates.title')} description={t('admin.updates.subtitle')} />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader
                        title={t('admin.updates.installed')}
                        actions={
                            checkEnabled ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => router.get('/admin/updates', { refresh: 1 }, { preserveScroll: true })}
                                >
                                    {t('admin.updates.check_again')}
                                </Button>
                            ) : undefined
                        }
                    />
                    <CardBody className="space-y-4">
                        <p className="text-3xl font-semibold tabular-nums text-slate-900">{current}</p>

                        {!checkEnabled ? (
                            <Notice title={t('admin.updates.check_disabled_title')} tone="neutral">
                                {t('admin.updates.check_disabled')}
                            </Notice>
                        ) : release === null ? (
                            <p className="text-sm text-slate-600">{t('admin.updates.up_to_date')}</p>
                        ) : (
                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-slate-900">
                                        {t('admin.updates.available')}: {release.version}
                                    </span>
                                    <Badge tone={release.step === 'major' ? 'amber' : 'blue'}>
                                        {t(`admin.updates.steps.${release.step}`)}
                                    </Badge>
                                    {release.is_pre_release && <Badge tone="amber">{t('admin.updates.pre_release')}</Badge>}
                                    {release.published_at && (
                                        <span className="text-xs text-slate-500">
                                            {t('admin.updates.published', { time: relativeTime(release.published_at, t) })}
                                        </span>
                                    )}
                                </div>

                                {release.step === 'major' && (
                                    <Notice title={t('admin.updates.steps.major')} tone="warning">
                                        {t('admin.updates.major_warning')}
                                    </Notice>
                                )}

                                {release.notes !== '' && (
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                            {t('admin.updates.notes')}
                                        </p>
                                        {/*
                                          * Rendered from parsed Markdown into React
                                          * elements, never from an HTML string: release
                                          * notes are the one piece of content here that
                                          * comes from outside this instance.
                                          */}
                                        <div className="mt-1 max-h-64 overflow-auto rounded-lg bg-slate-50 p-3">
                                            <ReleaseNotes notes={release.notes} />
                                        </div>
                                    </div>
                                )}

                                <div className="flex flex-wrap items-center gap-3">
                                    {canSelfUpgrade && !running && (
                                        <Button onClick={() => setConfirming(true)} disabled={form.processing}>
                                            {t('admin.updates.install', { version: release.version })}
                                        </Button>
                                    )}
                                    {release.url !== '' && (
                                        <a
                                            href={release.url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                            className="text-sm font-medium text-brand-600 hover:text-brand-700"
                                        >
                                            {t('admin.updates.view_on_github')}
                                        </a>
                                    )}
                                </div>
                            </div>
                        )}

                        {checkEnabled && (
                            <p className="text-xs text-slate-500">
                                {t('admin.updates.repository', { repository })}
                                {checkedAt && ` · ${t('admin.updates.checked_at', { time: relativeTime(checkedAt, t) })}`}
                            </p>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title={t('admin.updates.readiness')} />
                    <CardBody>
                        <ul className="space-y-2.5">
                            {checks.map((check) => (
                                <li key={check.key} className="flex gap-2 text-sm">
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                            check.ok ? 'bg-emerald-500' : check.blocking ? 'bg-rose-500' : 'bg-amber-500',
                                        )}
                                    />
                                    <span>
                                        <span className="font-medium text-slate-900">
                                            {t(`admin.updates.checks.${check.key}`)}
                                        </span>
                                        <span className="sr-only">
                                            {' — '}
                                            {check.ok
                                                ? t('admin.updates.passed')
                                                : check.blocking
                                                  ? t('admin.updates.blocking')
                                                  : t('admin.updates.warning')}
                                        </span>
                                        {!check.ok && (
                                            <span className="mt-0.5 block text-xs text-slate-600">
                                                {t(`admin.updates.check_notes.${check.key}`)}
                                            </span>
                                        )}
                                        {check.detail && (
                                            <span className="mt-0.5 block text-xs text-slate-500">{check.detail}</span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardBody>
                </Card>
            </div>

            {!canSelfUpgrade && (
                <Card className="mt-4">
                    <CardHeader title={t('admin.updates.cannot_self_upgrade_title')} />
                    <CardBody className="space-y-3 text-sm text-slate-700">
                        <p>{t('admin.updates.cannot_self_upgrade')}</p>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {t('admin.updates.command_docker')}
                            </p>
                            <pre className="mt-1 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">
                                docker compose pull{'\n'}docker compose up -d
                            </pre>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {t('admin.updates.command_source')}
                            </p>
                            <pre className="mt-1 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">
                                php artisan ticktz:upgrade
                            </pre>
                        </div>
                    </CardBody>
                </Card>
            )}

            {live && (
                <Card className="mt-4">
                    <CardHeader
                        title={running ? t('admin.updates.progress') : t('admin.updates.history')}
                        actions={<Badge tone={STATUS_TONE[live.status]}>{t(`admin.updates.statuses.${live.status}`)}</Badge>}
                    />
                    <CardBody className="space-y-3">
                        <p className="text-sm text-slate-700">
                            {live.from_version} → {live.to_version}
                            {live.requester && ` · ${live.requester}`}
                        </p>

                        {live.abandoned && (
                            <Notice title={t('admin.updates.statuses.queued')} tone="warning">
                                {t('admin.updates.abandoned')}
                            </Notice>
                        )}

                        {live.status === 'failed' && live.error && (
                            <Notice title={t('admin.updates.statuses.failed')} tone="danger">
                                {live.error}
                            </Notice>
                        )}

                        {live.status === 'installed' && (
                            <Notice title={t('admin.updates.statuses.installed')} tone="success">
                                {t('admin.updates.installed_notice', { version: live.to_version })}{' '}
                                {t('admin.updates.rollback')}
                            </Notice>
                        )}

                        {live.log && (
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {t('admin.updates.log')}
                                </p>
                                <pre
                                    aria-live={running ? 'polite' : undefined}
                                    className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700"
                                >
                                    {live.log}
                                </pre>
                            </div>
                        )}
                    </CardBody>
                </Card>
            )}

            <Card className="mt-4">
                <CardHeader title={t('admin.updates.history')} />
                {history.length === 0 ? (
                    <CardBody>
                        <EmptyState title={t('admin.updates.no_history')} />
                    </CardBody>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {history.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-5 py-2.5 text-sm">
                                <span className="font-medium text-slate-900">
                                    {row.from_version} → {row.to_version}
                                </span>
                                <Badge tone={STATUS_TONE[row.status]}>{t(`admin.updates.statuses.${row.status}`)}</Badge>
                                {row.requester && <span className="text-slate-600">{row.requester}</span>}
                                <span className="ml-auto whitespace-nowrap text-xs text-slate-500">
                                    {relativeTime(row.finished_at ?? row.created_at, t)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {release && (
                <ConfirmDialog
                    open={confirming}
                    title={t('admin.updates.install_confirm_title', { version: release.version })}
                    message={t('admin.updates.install_confirm')}
                    confirmLabel={t('admin.updates.install', { version: release.version })}
                    onConfirm={install}
                    onCancel={() => setConfirming(false)}
                    destructive={false}
                    processing={form.processing}
                />
            )}
        </AdminLayout>
    );
}

function Notice({
    title,
    tone,
    children,
}: {
    title: string;
    tone: 'neutral' | 'info' | 'warning' | 'danger' | 'success';
    children: React.ReactNode;
}) {
    const tones = {
        neutral: 'border-slate-200 bg-slate-50 text-slate-700',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
        danger: 'border-rose-200 bg-rose-50 text-rose-900',
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    } as const;

    return (
        <div className={cn('rounded-lg border p-3 text-sm', tones[tone])}>
            <p className="font-medium">{title}</p>
            <p className="mt-1">{children}</p>
        </div>
    );
}
