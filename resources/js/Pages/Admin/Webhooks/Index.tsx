import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { IconPlus, IconX } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    CheckboxGroup,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Pagination,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TextInput,
    Toggle,
} from '@/Components/UI';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime, relativeTime } from '@/lib/datetime';
import type { Paginated } from '@/types';

/*
|--------------------------------------------------------------------------
| Shapes
|--------------------------------------------------------------------------
*/

interface Subscription {
    id: number;
    name: string;
    description: string | null;
    url: string;
    events: string[];
    is_active: boolean;
    signed: boolean;
    last_delivered_at: string | null;
    last_failed_at: string | null;
    consecutive_failures: number;
    created_at: string | null;
}

interface Delivery {
    id: number;
    event: string;
    status: 'pending' | 'delivered' | 'failed';
    attempts: number;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    duration_ms: number | null;
    delivered_at: string | null;
    created_at: string | null;
    subscription: { id: number; name: string } | null;
}

interface EventOption {
    key: string;
    label: string;
}

const STATUS_TONE = {
    pending: 'amber',
    delivered: 'green',
    failed: 'red',
} as const;

/**
 * Webhook subscriptions and their delivery log.
 *
 * The delivery table is the point of the screen. Nobody opens this page to
 * admire a list of URLs — they open it because somebody's system did not hear
 * about a ticket, and what they need is the last attempt, the status that came
 * back and the error text, on one screen and without a database client.
 */
export default function Webhooks({
    subscriptions,
    deliveries,
    events,
    failureLimit,
    newSecret,
}: {
    subscriptions: Paginated<Subscription>;
    deliveries: Delivery[];
    events: EventOption[];
    failureLimit: number;
    newSecret: { id: number; secret: string } | null;
}) {
    const { t, locale } = useTranslations();
    const [editing, setEditing] = useState<Subscription | null>(null);
    const [creating, setCreating] = useState(false);
    const [deleting, setDeleting] = useState<Subscription | null>(null);
    const [secret, setSecret] = useState(newSecret);

    const form = useForm<{
        name: string;
        description: string;
        url: string;
        events: string[];
        is_active: boolean;
    }>({
        name: '',
        description: '',
        url: '',
        events: [],
        is_active: true,
    });

    const openCreate = () => {
        form.reset();
        form.clearErrors();
        setCreating(true);
    };

    const openEdit = (subscription: Subscription) => {
        form.setData({
            name: subscription.name,
            description: subscription.description ?? '',
            url: subscription.url,
            events: subscription.events,
            is_active: subscription.is_active,
        });
        form.clearErrors();
        setEditing(subscription);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (editing) {
            form.put(route('admin.webhooks.update', editing.id), {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
            });

            return;
        }

        form.post(route('admin.webhooks.store'), {
            preserveScroll: true,
            onSuccess: (page) => {
                setCreating(false);
                setSecret((page.props.newSecret as { id: number; secret: string } | null) ?? null);
            },
        });
    };

    return (
        <AdminLayout title={t('api.webhook_admin.title')}>
            <PageHeader
                title={t('api.webhook_admin.title')}
                description={t('api.webhook_admin.subtitle')}
                actions={
                    <Button onClick={openCreate}>
                        <IconPlus className="h-4 w-4" />
                        {t('api.webhook_admin.create')}
                    </Button>
                }
            />

            <Card>
                <CardBody className="p-0">
                    {subscriptions.data.length === 0 ? (
                        <EmptyState
                            title={t('api.webhook_admin.empty')}
                            description={t('api.webhook_admin.subtitle')}
                        />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('api.webhook_admin.name')}</TH>
                                    <TH>{t('api.webhook_admin.events')}</TH>
                                    <TH>{t('api.webhook_admin.last_delivered')}</TH>
                                    <TH>{t('api.webhook_admin.failures')}</TH>
                                    <TH className="w-px" />
                                </TR>
                            </THead>
                            <TBody>
                                {subscriptions.data.map((subscription) => (
                                    <TR key={subscription.id}>
                                        <TD>
                                            <button
                                                type="button"
                                                onClick={() => openEdit(subscription)}
                                                className="text-left font-medium text-brand-700 hover:underline"
                                            >
                                                {subscription.name}
                                            </button>
                                            <span className="block break-all text-xs text-slate-500">
                                                {subscription.url}
                                            </span>
                                            {!subscription.is_active &&
                                            subscription.consecutive_failures >= failureLimit ? (
                                                <span className="mt-1 block text-xs text-red-600">
                                                    {t('api.webhook_admin.auto_disabled', {
                                                        count: subscription.consecutive_failures,
                                                    })}
                                                </span>
                                            ) : null}
                                        </TD>
                                        <TD>
                                            <span className="flex flex-wrap gap-1">
                                                {subscription.events.map((event) => (
                                                    <Badge key={event} tone="slate">
                                                        {event}
                                                    </Badge>
                                                ))}
                                            </span>
                                        </TD>
                                        <TD className="text-sm text-slate-600">
                                            {relativeTime(subscription.last_delivered_at, t)}
                                        </TD>
                                        <TD>
                                            {subscription.consecutive_failures > 0 ? (
                                                <Badge tone="red">{subscription.consecutive_failures}</Badge>
                                            ) : (
                                                <Badge tone="green">0</Badge>
                                            )}
                                        </TD>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="secondary"
                                                    className="whitespace-nowrap"
                                                    onClick={() =>
                                                        form.post(route('admin.webhooks.test', subscription.id), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    {t('api.webhook_admin.test')}
                                                </Button>
                                                <Button variant="ghost" onClick={() => setDeleting(subscription)}>
                                                    <IconX className="h-4 w-4" />
                                                    <span className="sr-only">{t('common.actions.delete')}</span>
                                                </Button>
                                            </div>
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardBody>
                {subscriptions.last_page > 1 ? (
                    <CardBody>
                        <Pagination page={subscriptions} />
                    </CardBody>
                ) : null}
            </Card>

            {/* The reason anyone opens this page. */}
            <Card className="mt-6">
                <CardHeader title={t('api.webhook_admin.deliveries')} />
                <CardBody className="p-0">
                    {deliveries.length === 0 ? (
                        <EmptyState title={t('api.webhook_admin.no_deliveries')} />
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('api.webhook_admin.event')}</TH>
                                    <TH>{t('api.webhook_admin.subscription')}</TH>
                                    <TH>{t('common.labels.status')}</TH>
                                    <TH>{t('api.webhook_admin.response')}</TH>
                                    <TH>{t('api.webhook_admin.duration')}</TH>
                                    <TH>{t('common.labels.created_at')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {deliveries.map((delivery) => (
                                    <TR key={delivery.id}>
                                        <TD className="font-mono text-xs">{delivery.event}</TD>
                                        <TD className="text-sm">{delivery.subscription?.name ?? '—'}</TD>
                                        <TD>
                                            <Badge tone={STATUS_TONE[delivery.status]}>
                                                {t(`api.webhook_admin.delivery_status.${delivery.status}`)}
                                            </Badge>
                                            {delivery.attempts > 1 ? (
                                                <span className="ml-1 text-xs text-slate-500">
                                                    ×{delivery.attempts}
                                                </span>
                                            ) : null}
                                        </TD>
                                        <TD className="max-w-xs">
                                            {delivery.response_status ? (
                                                <span className="font-mono text-xs">{delivery.response_status}</span>
                                            ) : null}
                                            {delivery.error ? (
                                                <span className="block truncate text-xs text-red-600">
                                                    {delivery.error}
                                                </span>
                                            ) : null}
                                        </TD>
                                        <TD className="text-sm text-slate-600">
                                            {delivery.duration_ms !== null ? `${delivery.duration_ms} ms` : '—'}
                                        </TD>
                                        <TD className="text-sm text-slate-600">
                                            {formatDateTime(delivery.created_at, locale)}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </CardBody>
            </Card>

            <Modal
                open={creating || editing !== null}
                onClose={() => {
                    setCreating(false);
                    setEditing(null);
                }}
                title={editing ? editing.name : t('api.webhook_admin.create')}
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('api.webhook_admin.name')} error={form.errors.name}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                autoFocus
                            />
                        )}
                    </Field>

                    <Field
                        label={t('api.webhook_admin.url')}
                        help={t('api.webhook_admin.url_help')}
                        error={form.errors.url}
                    >
                        {(props) => (
                            <TextInput
                                {...props}
                                type="url"
                                value={form.data.url}
                                onChange={(event) => form.setData('url', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('api.webhook_admin.events')} error={form.errors.events}>
                        {() => (
                            <CheckboxGroup
                                options={events.map((event) => ({
                                    value: event.key,
                                    label: event.key,
                                    description: event.label,
                                }))}
                                selected={form.data.events}
                                onChange={(values) => form.setData('events', values)}
                            />
                        )}
                    </Field>

                    <Toggle
                        checked={form.data.is_active}
                        onChange={(value) => form.setData('is_active', value)}
                        label={t('api.webhook_admin.active')}
                    />

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                setCreating(false);
                                setEditing(null);
                            }}
                        >
                            {t('common.actions.cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {t('common.actions.save')}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* The signing secret, shown once. */}
            <Modal open={secret !== null} onClose={() => setSecret(null)} title={t('api.webhook_admin.create')}>
                <div className="space-y-4">
                    <p className="text-sm text-slate-600">{t('api.webhook_admin.secret_once')}</p>
                    <code className="block select-all break-all rounded-lg bg-slate-900 px-3 py-2 font-mono text-xs text-slate-100">
                        {secret?.secret}
                    </code>
                    <div className="flex justify-end">
                        <Button type="button" onClick={() => setSecret(null)}>
                            {t('common.actions.close')}
                        </Button>
                    </div>
                </div>
            </Modal>

            <ConfirmDialog
                open={deleting !== null}
                onCancel={() => setDeleting(null)}
                title={t('common.actions.delete')}
                message={t('api.webhook_admin.delete_confirm')}
                onConfirm={() => {
                    if (deleting) {
                        form.delete(route('admin.webhooks.destroy', deleting.id), {
                            preserveScroll: true,
                            onFinish: () => setDeleting(null),
                        });
                    }
                }}
            />
        </AdminLayout>
    );
}
