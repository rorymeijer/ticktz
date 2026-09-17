import { Link } from '@inertiajs/react';

import AdminLayout from '@/Layouts/AdminLayout';
import { Card, CardBody, CardHeader, EmptyState, PageHeader } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { relativeTime } from '@/lib/datetime';

interface ActivityEntry {
    id: number;
    event: string;
    description: string | null;
    subject_type: string;
    actor: { name: string };
    created_at: string | null;
}

export default function AdminIndex({
    stats,
    recentActivity,
}: {
    stats: Record<string, number>;
    recentActivity: ActivityEntry[];
}) {
    const { t } = useTranslations();

    const tiles: { key: string; href?: string }[] = [
        { key: 'users', href: '/admin/users' },
        { key: 'active_users' },
        { key: 'agents' },
        { key: 'teams', href: '/admin/teams' },
        { key: 'organizations', href: '/admin/organizations' },
        { key: 'roles', href: '/admin/roles' },
        { key: 'directories', href: '/admin/directories' },
    ];

    return (
        <AdminLayout title={t('admin.title')}>
            <PageHeader title={t('admin.title')} description={t('admin.subtitle')} />

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                {tiles.map((tile) => {
                    // Not <dt>/<dd>: each tile is wrapped in a <Link>, and HTML only
                    // allows <div> between a <dl> and its terms — so the description-list
                    // markup was invalid and bought nothing. These are stat tiles.
                    const content = (
                        <>
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {t(`admin.stats.${tile.key}`)}
                            </p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
                                {stats[tile.key] ?? 0}
                            </p>
                        </>
                    );

                    return tile.href ? (
                        <Link
                            key={tile.key}
                            href={tile.href}
                            className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-card transition-colors hover:border-brand-300"
                        >
                            {content}
                        </Link>
                    ) : (
                        <div key={tile.key} className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-card">
                            {content}
                        </div>
                    );
                })}
            </div>

            <Card className="mt-6">
                <CardHeader
                    title={t('admin.recent_activity')}
                    actions={
                        <Link href="/admin/audit-log" className="text-xs font-medium text-brand-600 hover:text-brand-700">
                            {t('admin.nav.audit')}
                        </Link>
                    }
                />
                {recentActivity.length === 0 ? (
                    <CardBody>
                        <EmptyState title={t('common.empty.title')} description={t('common.empty.description')} />
                    </CardBody>
                ) : (
                    <ul className="divide-y divide-slate-100">
                        {recentActivity.map((entry) => (
                            <li key={entry.id} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-5 py-2.5 text-sm">
                                <span className="font-medium text-slate-900">{entry.actor.name}</span>
                                <span className="text-slate-600">{entry.description ?? entry.event}</span>
                                <span className="ml-auto whitespace-nowrap text-xs text-slate-500">
                                    {relativeTime(entry.created_at, t)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </AdminLayout>
    );
}
