import { Head, useForm } from '@inertiajs/react';

import { BrandLockup } from '@/Components/Brand';
import { IconCheckCircle, IconAlert } from '@/Components/Icons';
import { Button, Card, CardBody, Field, Textarea } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { ReactNode } from 'react';
import type { Approval } from '@/types/approvals';
import type { UserSummary } from '@/types/tickets';
import { RichText } from '@/Components/RichText/RichText';

/**
 * Deciding an approval from an e-mail, without signing in.
 *
 * Its own bare shell rather than the portal or agent one: the person reading it
 * may have no Ticktz account at all, and a page wrapped in navigation they
 * cannot use is a page that asks them to log in before they can answer.
 *
 * Nothing on this page decides anything by being opened. The buttons post.
 */
export default function ApprovalToken({
    token,
    decision,
    approval,
    outcome,
}: {
    token: string | null;
    decision: { id: number; step_name: string | null; approver: UserSummary | null } | null;
    approval?: Approval;
    outcome?: string | null;
}) {
    return (
        <Shell>
            {outcome ? (
                <Done outcome={outcome} />
            ) : decision && approval && token ? (
                <Decide token={token} approval={approval} approver={decision.approver} />
            ) : (
                <Expired />
            )}
        </Shell>
    );
}

function Decide({ token, approval, approver }: { token: string; approval: Approval; approver: UserSummary | null }) {
    const { t } = useTranslations();
    const form = useForm({ decision: 'approved', comment: '' });

    const submit = (value: 'approved' | 'rejected') => {
        form.transform((data) => ({ ...data, decision: value }));
        form.post(`/approvals/decide/${token}`);
    };

    return (
        <Card>
            <CardBody>
                <h1 className="text-lg font-semibold tracking-tight text-slate-900">{t('approvals.token.title')}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {t('approvals.token.intro')}
                    {approver ? ` (${approver.name})` : ''}
                </p>

                <dl className="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                    <div>
                        <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t('approvals.fields.subject')}
                        </dt>
                        <dd className="mt-0.5 font-medium text-slate-900">{approval.subject}</dd>
                    </div>

                    {approval.reason ? (
                        <div>
                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {t('approvals.fields.reason')}
                            </dt>
                            <dd className="mt-0.5">
                                <RichText html={approval.reason} />
                            </dd>
                        </div>
                    ) : null}

                    {approval.ticket?.key ? (
                        <div>
                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {approval.ticket.key}
                            </dt>
                            <dd className="mt-0.5 text-slate-700">{approval.ticket.subject}</dd>
                            {approval.ticket.description ? (
                                <dd className="mt-1">
                                    <RichText html={approval.ticket.description} className="text-xs text-slate-500" />
                                </dd>
                            ) : null}
                        </div>
                    ) : null}

                    {approval.ticket?.requester ? (
                        <div>
                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                {t('approvals.ticket.requested_by', { name: '' }).trim()}
                            </dt>
                            <dd className="mt-0.5 text-slate-700">{approval.ticket.requester.name}</dd>
                        </div>
                    ) : null}
                </dl>

                <div className="mt-5 border-t border-slate-100 pt-4">
                    <Field
                        label={t('approvals.fields.comment')}
                        error={form.errors.comment}
                        help={t('approvals.fields.comment_help')}
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={3}
                                maxLength={2000}
                                value={form.data.comment}
                                onChange={(event) => form.setData('comment', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button size="lg" disabled={form.processing} onClick={() => submit('approved')}>
                            {t('approvals.actions.approve')}
                        </Button>
                        <Button
                            size="lg"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => submit('rejected')}
                        >
                            {t('approvals.actions.reject')}
                        </Button>
                    </div>
                </div>
            </CardBody>
        </Card>
    );
}

function Done({ outcome }: { outcome: string }) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardBody className="flex flex-col items-center gap-3 py-12 text-center">
                <IconCheckCircle
                    className={outcome === 'approved' ? 'h-8 w-8 text-emerald-500' : 'h-8 w-8 text-slate-500'}
                />
                <p className="text-sm font-medium text-slate-900">
                    {t(outcome === 'approved' ? 'approvals.token.done_approved' : 'approvals.token.done_rejected')}
                </p>
                <a href="/login" className="text-sm font-medium text-brand-600 hover:text-brand-700">
                    {t('approvals.token.sign_in')}
                </a>
            </CardBody>
        </Card>
    );
}

/**
 * One page for "expired", "already answered" and "never existed". Which of the
 * three it was is information about somebody else's approval, and the person
 * holding a dead link cannot act on any of them anyway.
 */
function Expired() {
    const { t } = useTranslations();

    return (
        <Card>
            <CardBody className="flex flex-col items-center gap-3 py-12 text-center">
                <IconAlert className="h-8 w-8 text-amber-500" />
                <p className="text-sm font-semibold text-slate-900">{t('approvals.token.expired_title')}</p>
                <p className="max-w-sm text-sm text-slate-500">{t('approvals.token.expired')}</p>
                <a href="/login" className="text-sm font-medium text-brand-600 hover:text-brand-700">
                    {t('approvals.token.sign_in')}
                </a>
            </CardBody>
        </Card>
    );
}

function Shell({ children }: { children: ReactNode }) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('approvals.token.title')} />
            <div className="min-h-screen bg-slate-50 px-4 py-10 sm:px-6">
                <div className="mx-auto max-w-xl">
                    <div className="mb-6 flex justify-center">
                        <BrandLockup name="Ticktz" />
                    </div>
                    {children}
                </div>
            </div>
        </>
    );
}
