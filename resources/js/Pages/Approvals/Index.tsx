import { Link, router } from '@inertiajs/react';
import type { ReactNode } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import PortalLayout from '@/Layouts/PortalLayout';
import { ApprovalBadge } from '@/Components/Approvals/ApprovalBadge';
import { ApprovalSteps } from '@/Components/Approvals/ApprovalSteps';
import { DecideButtons } from '@/Components/Approvals/DecideDialog';
import { IconAlert } from '@/Components/Icons';
import { Badge, Card, CardBody, EmptyState, Pagination } from '@/Components/UI';
import { useAuth } from '@/hooks/useAuth';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { deadline, relativeTime } from '@/lib/datetime';
import type { Paginated } from '@/types';
import type { Approval } from '@/types/approvals';

/**
 * One approval inbox for everybody.
 *
 * An approver is very often a budget holder who is not an agent and has never
 * seen the console, so this page picks the shell that matches its reader
 * rather than living inside one of them. Same URL in every notification,
 * whoever opens it.
 */
export default function ApprovalsIndex({
    approvals,
    filters,
    can,
}: {
    approvals: Paginated<Approval>;
    filters: { filter: string };
    can: { view_all: boolean };
}) {
    const { t } = useTranslations();
    const { user } = useAuth();

    const Shell = user?.is_agent ? AgentShell : RequesterShell;

    return (
        <Shell title={t('approvals.title')}>
            <div className="mb-5">
                <h2 className="text-xl font-semibold tracking-tight text-slate-900">{t('approvals.title')}</h2>
                <p className="mt-1 text-sm text-slate-500">{t('approvals.subtitle')}</p>
            </div>

            {can.view_all ? (
                <div className="mb-4 flex flex-wrap gap-1.5">
                    {(['mine', 'all'] as const).map((value) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() =>
                                router.get('/approvals', { filter: value }, { preserveState: true, replace: true })
                            }
                            aria-current={filters.filter === value ? 'page' : undefined}
                            className={cn(
                                'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                                filters.filter === value
                                    ? 'bg-brand-600 text-white'
                                    : 'bg-white text-slate-600 shadow-card hover:text-slate-900',
                            )}
                        >
                            {t(`approvals.filters.${value}`)}
                        </button>
                    ))}
                </div>
            ) : null}

            {approvals.data.length > 0 ? (
                <div className="space-y-3">
                    {approvals.data.map((approval) => (
                        <ApprovalCard key={approval.id} approval={approval} />
                    ))}
                    <Pagination page={approvals} className="rounded-xl border border-slate-200 bg-white" />
                </div>
            ) : (
                <Card>
                    <EmptyState
                        title={filters.filter === 'all' ? t('approvals.empty_all') : t('approvals.empty')}
                        description={t('approvals.empty_hint')}
                    />
                </Card>
            )}
        </Shell>
    );
}

function ApprovalCard({ approval }: { approval: Approval }) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardBody>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <ApprovalBadge status={approval.status} />
                            {approval.is_overdue ? (
                                <Badge tone="red">
                                    <IconAlert className="h-3 w-3" />
                                    {t('approvals.ticket.overdue')}
                                </Badge>
                            ) : null}
                            {approval.ticket?.key ? (
                                <Link
                                    href={`/portal/requests/${approval.ticket.key}`}
                                    className="font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                                >
                                    {approval.ticket.key}
                                </Link>
                            ) : null}
                        </div>

                        <h3 className="mt-1.5 font-medium text-slate-900">{approval.subject}</h3>

                        {approval.reason ? (
                            <p className="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-600">
                                {approval.reason}
                            </p>
                        ) : null}

                        <p className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-slate-400">
                            {approval.ticket?.requester ? (
                                <span>
                                    {t('approvals.ticket.requested_by', { name: approval.ticket.requester.name })}
                                </span>
                            ) : null}
                            <span aria-hidden="true">·</span>
                            <span>{relativeTime(approval.created_at, t)}</span>
                            {approval.due_at ? (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>{deadline(approval.due_at, t)}</span>
                                </>
                            ) : null}
                        </p>
                    </div>

                    {approval.my_decision_id ? (
                        <DecideButtons decisionId={approval.my_decision_id} subject={approval.subject} />
                    ) : null}
                </div>

                {approval.steps.length > 0 ? (
                    <div className="mt-4 border-t border-slate-100 pt-3">
                        <ApprovalSteps steps={approval.steps} />
                    </div>
                ) : null}
            </CardBody>
        </Card>
    );
}

function AgentShell({ title, children }: { title: string; children: ReactNode }) {
    return (
        <AppLayout title={title} header={title}>
            {children}
        </AppLayout>
    );
}

function RequesterShell({ title, children }: { title: string; children: ReactNode }) {
    return <PortalLayout title={title}>{children}</PortalLayout>;
}
