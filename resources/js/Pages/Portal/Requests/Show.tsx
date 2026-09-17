import { Link, useForm } from '@inertiajs/react';
import { useRef, type FormEventHandler } from 'react';

import PortalLayout from '@/Layouts/PortalLayout';
import { ApprovalBadge } from '@/Components/Approvals/ApprovalBadge';
import { ApprovalSteps } from '@/Components/Approvals/ApprovalSteps';
import { AttachmentList } from '@/Components/Tickets/Timeline';
import { StatusBadge } from '@/Components/Tickets/Badges';
import { IconPaperclip, IconX } from '@/Components/Icons';
import { Avatar, Button, Card, CardBody, CardHeader, Textarea } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { Approval } from '@/types/approvals';
import { cn } from '@/lib/cn';
import { formatDate, formatDateTime } from '@/lib/datetime';
import type { AttachmentSummary, StatusSummary, UserSummary } from '@/types/tickets';
import { RichText } from '@/Components/RichText/RichText';

interface PortalComment {
    id: number;
    body: string;
    author: Partial<UserSummary> & { name: string };
    is_mine: boolean;
    created_at: string | null;
    attachments: AttachmentSummary[];
}

interface PortalRequest {
    key: string;
    subject: string;
    description: string | null;
    status: StatusSummary | null;
    priority: { name: string } | null;
    request_type: string | null;
    assignee: UserSummary | null;
    created_at: string | null;
    resolved_at: string | null;
    fields: { key: string; label: string; display: string }[];
    attachments: AttachmentSummary[];
}

export default function PortalRequestShow({
    request,
    comments,
    approvals,
    canComment,
}: {
    request: PortalRequest;
    comments: PortalComment[];
    approvals: Approval[];
    canComment: boolean;
}) {
    const { t, locale } = useTranslations();
    const fileInput = useRef<HTMLInputElement>(null);

    const form = useForm<{ body: string; attachments: File[] }>({ body: '', attachments: [] });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.post(`/portal/requests/${request.key}/comments`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const isClosed = request.status?.is_open === false;

    return (
        <PortalLayout title={`${request.key} — ${request.subject}`}>
            <div className="mx-auto max-w-3xl">
                <Link href="/portal/requests" className="text-sm text-slate-500 hover:text-slate-800">
                    ← {t('portal.requests.title')}
                </Link>

                <div className="mb-5 mt-2 flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <p className="font-mono text-xs font-semibold text-brand-600">{request.key}</p>
                        <h2 className="mt-0.5 text-xl font-semibold tracking-tight text-slate-900">
                            {request.subject}
                        </h2>
                        <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                            {request.request_type ? <span>{request.request_type}</span> : null}
                            <span>{t('portal.requests.opened_on', { date: formatDate(request.created_at, locale) })}</span>
                            <span>
                                {request.assignee
                                    ? t('portal.requests.handled_by', { name: request.assignee.name })
                                    : t('portal.requests.unassigned')}
                            </span>
                        </p>
                    </div>
                    <StatusBadge status={request.status} />
                </div>

                <div className="space-y-4">
                    {request.description ? (
                        <Card>
                            <CardBody>
                                <RichText html={request.description} className="break-words" />
                            </CardBody>
                        </Card>
                    ) : null}

                    {request.attachments.length > 0 ? (
                        <Card>
                            <CardBody>
                                <AttachmentList files={request.attachments} />
                            </CardBody>
                        </Card>
                    ) : null}

                    {/* A requester waiting on a signature should be able to see
                        that, and see whose. "Nothing has happened" and "your
                        manager has not answered yet" are very different
                        messages, and only one of them produces a chase-up
                        e-mail to the service desk. */}
                    {approvals.length > 0 ? (
                        <Card>
                            <CardHeader title={t('approvals.portal.title')} />
                            <CardBody className="space-y-3">
                                {approvals.slice(0, 1).map((approval) => (
                                    <div key={approval.id} className="space-y-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <ApprovalBadge status={approval.status} />
                                            <span className="text-sm text-slate-600">
                                                {t(
                                                    approval.status === 'approved'
                                                        ? 'approvals.portal.approved'
                                                        : approval.status === 'rejected'
                                                          ? 'approvals.portal.rejected'
                                                          : 'approvals.portal.pending',
                                                )}
                                            </span>
                                        </div>
                                        <ApprovalSteps steps={approval.steps} />
                                    </div>
                                ))}
                            </CardBody>
                        </Card>
                    ) : null}

                    {request.fields.length > 0 ? (
                        <Card>
                            <CardHeader title={t('portal.requests.details')} />
                            <CardBody>
                                <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                    {request.fields.map((field) => (
                                        <div key={field.key}>
                                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                                {field.label}
                                            </dt>
                                            <dd className="mt-0.5 text-sm text-slate-800">{field.display}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardBody>
                        </Card>
                    ) : null}

                    <Card>
                        <CardHeader title={t('portal.requests.conversation')} />
                        {comments.length === 0 ? (
                            <CardBody className="text-sm text-slate-500">{t('tickets.timeline.no_activity')}</CardBody>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {comments.map((comment) => (
                                    <li key={comment.id} className={cn('px-5 py-4', comment.is_mine && 'bg-slate-50/60')}>
                                        <div className="flex items-start gap-3">
                                            <Avatar
                                                name={comment.author.name}
                                                initials={
                                                    comment.author.initials ??
                                                    comment.author.name.slice(0, 1).toUpperCase()
                                                }
                                                color={comment.author.avatar_color ?? '#94a3b8'}
                                                size="sm"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-baseline gap-2">
                                                    <span className="text-sm font-medium text-slate-900">
                                                        {comment.is_mine ? t('portal.requests.you') : comment.author.name}
                                                    </span>
                                                    <time
                                                        className="ml-auto text-xs text-slate-500"
                                                        dateTime={comment.created_at ?? undefined}
                                                    >
                                                        {formatDateTime(comment.created_at, locale)}
                                                    </time>
                                                </div>
                                                <RichText html={comment.body} className="mt-1.5 break-words" />
                                                {comment.attachments.length > 0 ? (
                                                    <AttachmentList files={comment.attachments} />
                                                ) : null}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {canComment ? (
                        <Card>
                            <form onSubmit={submit}>
                                <CardBody className="space-y-3">
                                    {isClosed ? (
                                        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                            {t('portal.requests.closed_notice')}
                                        </p>
                                    ) : null}

                                    <Textarea
                                        value={form.data.body}
                                        onChange={(event) => form.setData('body', event.target.value)}
                                        rows={4}
                                        required
                                        aria-label={t('portal.requests.reply')}
                                        placeholder={t('portal.requests.reply_placeholder')}
                                    />
                                    {form.errors.body ? (
                                        <p className="text-xs text-red-600">{form.errors.body}</p>
                                    ) : null}

                                    {form.data.attachments.length > 0 ? (
                                        <ul className="flex flex-wrap gap-1.5">
                                            {form.data.attachments.map((file, index) => (
                                                <li
                                                    key={`${file.name}-${index}`}
                                                    className="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700"
                                                >
                                                    <span className="max-w-48 truncate">{file.name}</span>
                                                    <button
                                                        type="button"
                                                        aria-label={t('common.actions.remove')}
                                                        onClick={() =>
                                                            form.setData(
                                                                'attachments',
                                                                form.data.attachments.filter((_, i) => i !== index),
                                                            )
                                                        }
                                                        className="text-slate-500 hover:text-slate-700"
                                                    >
                                                        <IconX className="h-3 w-3" />
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : null}

                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900">
                                            <IconPaperclip className="h-4 w-4" />
                                            {t('portal.form.attachments')}
                                            <input
                                                ref={fileInput}
                                                type="file"
                                                multiple
                                                className="sr-only"
                                                onChange={(event) =>
                                                    form.setData('attachments', [
                                                        ...form.data.attachments,
                                                        ...Array.from(event.target.files ?? []),
                                                    ])
                                                }
                                            />
                                        </label>

                                        <Button
                                            type="submit"
                                            disabled={form.processing || form.data.body.trim() === ''}
                                        >
                                            {t('portal.requests.reply')}
                                        </Button>
                                    </div>
                                </CardBody>
                            </form>
                        </Card>
                    ) : null}
                </div>
            </div>
        </PortalLayout>
    );
}
