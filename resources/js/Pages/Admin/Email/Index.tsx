import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import { IconAlert, IconMail, IconPlus } from '@/Components/Icons';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    ConfirmDialog,
    EmptyState,
    Field,
    Modal,
    PageHeader,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TextInput,
    Textarea,
    Toggle,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { formatDateTime } from '@/lib/datetime';
import { slugify } from '@/lib/slug';
import type { PrioritySummary } from '@/types/tickets';

interface Channel {
    id: number;
    name: string;
    slug: string;
    address: string;
    from_name: string | null;
    reply_to: string | null;
    is_active: boolean;
    smtp_host: string | null;
    smtp_port: number;
    smtp_encryption: 'none' | 'ssl' | 'tls';
    smtp_username: string | null;
    has_smtp_password: boolean;
    imap_enabled: boolean;
    imap_host: string | null;
    imap_port: number;
    imap_encryption: 'none' | 'ssl' | 'tls' | 'starttls';
    imap_validate_cert: boolean;
    imap_username: string | null;
    has_imap_password: boolean;
    imap_folder: string;
    imap_processed_folder: string | null;
    imap_delete_after_processing: boolean;
    queue_id: number | null;
    team_id: number | null;
    request_type_id: number | null;
    priority_id: number | null;
    auto_provision_requesters: boolean;
    ignore_senders: string[];
    last_polled_at: string | null;
    last_poll_result: string | null;
}

interface Template {
    id: number;
    key: string;
    email_channel_id: number | null;
    locale: string;
    subject: string;
    body: string;
    is_active: boolean;
}

interface InboundRow {
    id: number;
    from: string;
    subject: string | null;
    status: 'pending' | 'processed' | 'ignored' | 'failed';
    reason: string | null;
    ticket_key: string | null;
    received_at: string | null;
}

interface Options {
    queues: { id: number; name: string }[];
    teams: { id: number; name: string }[];
    requestTypes: { id: number; name: string }[];
    priorities: PrioritySummary[];
    locales: string[];
}

const STATUS_TONES = {
    processed: 'green',
    ignored: 'slate',
    failed: 'red',
    pending: 'amber',
} as const;

export default function EmailIndex({
    channels,
    templates,
    templateKeys,
    placeholders,
    recentMessages,
    options,
}: {
    channels: Channel[];
    templates: Template[];
    templateKeys: string[];
    placeholders: string[];
    recentMessages: InboundRow[];
    options: Options;
}) {
    const { t, locale } = useTranslations();
    const [editing, setEditing] = useState<Channel | 'new' | null>(null);
    const [editingTemplate, setEditingTemplate] = useState<Template | string | null>(null);
    const [pendingDelete, setPendingDelete] = useState<Channel | null>(null);

    return (
        <AdminLayout
            title={t('admin.email.title')}
            actions={
                <>
                    {channels.some((channel) => channel.imap_enabled) ? (
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post('/admin/email/poll', {}, { preserveScroll: true })}
                        >
                            {t('admin.email.actions.poll_all')}
                        </Button>
                    ) : null}
                    <Button size="sm" icon={<IconPlus />} onClick={() => setEditing('new')}>
                        {t('admin.email.add')}
                    </Button>
                </>
            }
        >
            <PageHeader title={t('admin.email.title')} description={t('admin.email.subtitle')} />

            <div className="space-y-5">
                {channels.length === 0 ? (
                    <Card>
                        <EmptyState
                            title={t('admin.email.none')}
                            icon={<IconMail className="h-8 w-8" />}
                            action={<Button onClick={() => setEditing('new')}>{t('admin.email.add')}</Button>}
                        />
                    </Card>
                ) : (
                    channels.map((channel) => (
                        <Card key={channel.id}>
                            <CardHeader
                                title={
                                    <span className="flex flex-wrap items-center gap-2">
                                        {channel.name}
                                        <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                                            {channel.address}
                                        </code>
                                        <Badge tone={channel.is_active ? 'green' : 'slate'}>
                                            {channel.is_active
                                                ? t('common.labels.active')
                                                : t('common.labels.inactive')}
                                        </Badge>
                                        {channel.imap_enabled ? <Badge tone="blue">IMAP</Badge> : null}
                                    </span>
                                }
                                description={
                                    channel.last_polled_at
                                        ? `${t('admin.email.last_polled', {
                                              time: formatDateTime(channel.last_polled_at, locale),
                                          })} — ${channel.last_poll_result ?? ''}`
                                        : t('admin.email.never_polled')
                                }
                                actions={
                                    <>
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/admin/email/channels/${channel.id}/test`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('admin.email.actions.test')}
                                        </Button>
                                        {channel.imap_enabled ? (
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/admin/email/channels/${channel.id}/poll`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {t('admin.email.actions.poll')}
                                            </Button>
                                        ) : null}
                                        <Button variant="ghost" size="sm" onClick={() => setEditing(channel)}>
                                            {t('common.actions.edit')}
                                        </Button>
                                        <Button variant="ghost" size="sm" onClick={() => setPendingDelete(channel)}>
                                            {t('common.actions.delete')}
                                        </Button>
                                    </>
                                }
                            />
                        </Card>
                    ))
                )}

                <Card>
                    <CardHeader
                        title={t('admin.email.templates.title')}
                        description={t('admin.email.templates.description')}
                    />
                    <CardBody>
                        <ul className="divide-y divide-slate-100">
                            {templateKeys.map((key) => {
                                const overrides = templates.filter((template) => template.key === key);

                                return (
                                    <li key={key} className="flex flex-wrap items-center gap-3 py-2.5">
                                        <div className="min-w-56 flex-1">
                                            <p className="text-sm font-medium text-slate-800">
                                                {t(`admin.email.template_labels.${key}`)}
                                            </p>
                                            <code className="font-mono text-[11px] text-slate-400">{key}</code>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {overrides.length === 0 ? (
                                                <span className="text-xs text-slate-400">
                                                    {t('admin.email.templates.using_default')}
                                                </span>
                                            ) : (
                                                overrides.map((template) => (
                                                    <button
                                                        key={template.id}
                                                        type="button"
                                                        onClick={() => setEditingTemplate(template)}
                                                        className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-100"
                                                    >
                                                        {template.locale.toUpperCase()}
                                                        {template.email_channel_id
                                                            ? ` · ${
                                                                  channels.find(
                                                                      (channel) =>
                                                                          channel.id === template.email_channel_id,
                                                                  )?.name ?? ''
                                                              }`
                                                            : ''}
                                                    </button>
                                                ))
                                            )}
                                        </div>

                                        <Button variant="ghost" size="sm" onClick={() => setEditingTemplate(key)}>
                                            {t('admin.email.templates.add')}
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title={t('admin.email.inbound.title')}
                        description={t('admin.email.inbound.description')}
                    />
                    {recentMessages.length === 0 ? (
                        <CardBody>
                            <p className="text-sm text-slate-500">{t('admin.email.inbound.empty')}</p>
                        </CardBody>
                    ) : (
                        <Table>
                            <THead>
                                <TR>
                                    <TH>{t('admin.email.inbound.columns.received')}</TH>
                                    <TH>{t('admin.email.inbound.columns.from')}</TH>
                                    <TH>{t('admin.email.inbound.columns.subject')}</TH>
                                    <TH>{t('admin.email.inbound.columns.status')}</TH>
                                    <TH>{t('admin.email.inbound.columns.ticket')}</TH>
                                </TR>
                            </THead>
                            <TBody>
                                {recentMessages.map((message) => (
                                    <TR key={message.id}>
                                        <TD className="whitespace-nowrap text-xs text-slate-500">
                                            {formatDateTime(message.received_at, locale)}
                                        </TD>
                                        <TD className="max-w-56 truncate text-sm">{message.from}</TD>
                                        <TD className="max-w-72 truncate text-sm text-slate-700">
                                            {message.subject ?? '—'}
                                        </TD>
                                        <TD>
                                            <Badge tone={STATUS_TONES[message.status]}>
                                                {t(`admin.email.inbound.status.${message.status}`)}
                                            </Badge>
                                            {message.reason ? (
                                                <p className="mt-0.5 text-[11px] text-slate-400">{message.reason}</p>
                                            ) : null}
                                        </TD>
                                        <TD>
                                            {message.ticket_key ? (
                                                <a
                                                    href={`/agent/tickets/${message.ticket_key}`}
                                                    className="font-mono text-xs font-semibold text-brand-600 hover:text-brand-700"
                                                >
                                                    {message.ticket_key}
                                                </a>
                                            ) : (
                                                '—'
                                            )}
                                        </TD>
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    )}
                </Card>
            </div>

            {editing ? (
                <ChannelDialog
                    channel={editing === 'new' ? null : editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            ) : null}

            {editingTemplate ? (
                <TemplateDialog
                    template={typeof editingTemplate === 'string' ? null : editingTemplate}
                    templateKey={typeof editingTemplate === 'string' ? editingTemplate : editingTemplate.key}
                    channels={channels}
                    locales={options.locales}
                    placeholders={placeholders}
                    onClose={() => setEditingTemplate(null)}
                />
            ) : null}

            <ConfirmDialog
                open={pendingDelete !== null}
                onCancel={() => setPendingDelete(null)}
                onConfirm={() => {
                    if (pendingDelete) {
                        router.delete(`/admin/email/channels/${pendingDelete.id}`, {
                            preserveScroll: true,
                            onFinish: () => setPendingDelete(null),
                        });
                    }
                }}
                message={t('admin.email.confirm_delete')}
            />
        </AdminLayout>
    );
}

function ChannelDialog({
    channel,
    options,
    onClose,
}: {
    channel: Channel | null;
    options: Options;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const isEdit = channel !== null;

    const form = useForm({
        name: channel?.name ?? '',
        slug: channel?.slug ?? '',
        address: channel?.address ?? '',
        from_name: channel?.from_name ?? '',
        reply_to: channel?.reply_to ?? '',
        is_active: channel?.is_active ?? true,
        smtp_host: channel?.smtp_host ?? '',
        smtp_port: channel?.smtp_port ?? 587,
        smtp_encryption: channel?.smtp_encryption ?? 'tls',
        smtp_username: channel?.smtp_username ?? '',
        smtp_password: '',
        imap_enabled: channel?.imap_enabled ?? false,
        imap_host: channel?.imap_host ?? '',
        imap_port: channel?.imap_port ?? 993,
        imap_encryption: channel?.imap_encryption ?? 'ssl',
        imap_validate_cert: channel?.imap_validate_cert ?? true,
        imap_username: channel?.imap_username ?? '',
        imap_password: '',
        imap_folder: channel?.imap_folder ?? 'INBOX',
        imap_processed_folder: channel?.imap_processed_folder ?? '',
        imap_delete_after_processing: channel?.imap_delete_after_processing ?? false,
        queue_id: channel?.queue_id ? String(channel.queue_id) : '',
        team_id: channel?.team_id ? String(channel.team_id) : '',
        request_type_id: channel?.request_type_id ? String(channel.request_type_id) : '',
        priority_id: channel?.priority_id ? String(channel.priority_id) : '',
        auto_provision_requesters: channel?.auto_provision_requesters ?? true,
        ignore_senders: channel?.ignore_senders ?? ([] as string[]),
    });

    const section = (title: string) => (
        <p className="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</p>
    );

    return (
        <Modal
            open
            onClose={onClose}
            size="xl"
            title={isEdit ? t('admin.email.edit') : t('admin.email.add')}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/email/channels/${channel.id}`, {
                                      preserveScroll: true,
                                      onSuccess: onClose,
                                  })
                                : form.post('/admin/email/channels', { preserveScroll: true, onSuccess: onClose })
                        }
                    >
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                {section(t('admin.email.sections.identity'))}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.email.fields.name')} error={form.errors.name} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.name}
                                autoFocus
                                onChange={(event) => {
                                    form.setData('name', event.target.value);
                                    if (!isEdit) form.setData('slug', slugify(event.target.value));
                                }}
                            />
                        )}
                    </Field>

                    <Field label={t('admin.email.fields.slug')} error={form.errors.slug} required>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.slug}
                                className="font-mono"
                                onChange={(event) => form.setData('slug', slugify(event.target.value))}
                            />
                        )}
                    </Field>
                </div>

                <Field
                    label={t('admin.email.fields.address')}
                    help={t('admin.email.fields.address_help')}
                    error={form.errors.address}
                    required
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            type="email"
                            value={form.data.address}
                            onChange={(event) => form.setData('address', event.target.value)}
                        />
                    )}
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.email.fields.from_name')} error={form.errors.from_name}>
                        {(props) => (
                            <TextInput
                                {...props}
                                value={form.data.from_name}
                                onChange={(event) => form.setData('from_name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label={t('admin.email.fields.reply_to')} error={form.errors.reply_to}>
                        {(props) => (
                            <TextInput
                                {...props}
                                type="email"
                                value={form.data.reply_to}
                                onChange={(event) => form.setData('reply_to', event.target.value)}
                            />
                        )}
                    </Field>
                </div>

                {section(t('admin.email.sections.outgoing'))}

                <Field
                    label={t('admin.email.fields.smtp_host')}
                    help={t('admin.email.fields.smtp_host_help')}
                    error={form.errors.smtp_host}
                >
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.smtp_host}
                            onChange={(event) => form.setData('smtp_host', event.target.value)}
                        />
                    )}
                </Field>

                {form.data.smtp_host ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('admin.email.fields.smtp_port')} error={form.errors.smtp_port} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="number"
                                    value={form.data.smtp_port}
                                    onChange={(event) => form.setData('smtp_port', Number(event.target.value))}
                                />
                            )}
                        </Field>

                        <Field label={t('admin.email.fields.smtp_encryption')} error={form.errors.smtp_encryption}>
                            {(props) => (
                                <Select
                                    {...props}
                                    value={form.data.smtp_encryption}
                                    onChange={(event) =>
                                        form.setData('smtp_encryption', event.target.value as Channel['smtp_encryption'])
                                    }
                                >
                                    <option value="tls">{t('admin.email.encryption.tls')}</option>
                                    <option value="ssl">{t('admin.email.encryption.ssl')}</option>
                                    <option value="none">{t('admin.email.encryption.none')}</option>
                                </Select>
                            )}
                        </Field>

                        <Field label={t('admin.email.fields.smtp_username')} error={form.errors.smtp_username}>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.smtp_username}
                                    onChange={(event) => form.setData('smtp_username', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label={t('admin.email.fields.smtp_password')}
                            help={t('admin.email.fields.password_help')}
                            error={form.errors.smtp_password}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.smtp_password}
                                    placeholder={channel?.has_smtp_password ? '••••••••' : ''}
                                    onChange={(event) => form.setData('smtp_password', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>
                ) : null}

                {section(t('admin.email.sections.incoming'))}

                <Toggle
                    checked={form.data.imap_enabled}
                    onChange={(value) => form.setData('imap_enabled', value)}
                    label={t('admin.email.fields.imap_enabled')}
                />

                {form.data.imap_enabled ? (
                    <div className="space-y-4 rounded-lg border border-slate-200 p-3">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('admin.email.fields.imap_host')} error={form.errors.imap_host} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.imap_host}
                                        onChange={(event) => form.setData('imap_host', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.imap_port')} error={form.errors.imap_port} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="number"
                                        value={form.data.imap_port}
                                        onChange={(event) => form.setData('imap_port', Number(event.target.value))}
                                    />
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.imap_encryption')} error={form.errors.imap_encryption}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.imap_encryption}
                                        onChange={(event) =>
                                            form.setData(
                                                'imap_encryption',
                                                event.target.value as Channel['imap_encryption'],
                                            )
                                        }
                                    >
                                        <option value="ssl">{t('admin.email.encryption.ssl')}</option>
                                        <option value="starttls">{t('admin.email.encryption.starttls')}</option>
                                        <option value="tls">{t('admin.email.encryption.tls')}</option>
                                        <option value="none">{t('admin.email.encryption.none')}</option>
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.imap_folder')} error={form.errors.imap_folder} required>
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.imap_folder}
                                        className="font-mono"
                                        onChange={(event) => form.setData('imap_folder', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('admin.email.fields.imap_username')}
                                help={t('admin.email.fields.imap_username_help')}
                                error={form.errors.imap_username}
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        value={form.data.imap_username}
                                        onChange={(event) => form.setData('imap_username', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field
                                label={t('admin.email.fields.imap_password')}
                                help={t('admin.email.fields.password_help')}
                                error={form.errors.imap_password}
                            >
                                {(props) => (
                                    <TextInput
                                        {...props}
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.imap_password}
                                        placeholder={channel?.has_imap_password ? '••••••••' : ''}
                                        onChange={(event) => form.setData('imap_password', event.target.value)}
                                    />
                                )}
                            </Field>
                        </div>

                        <Field
                            label={t('admin.email.fields.imap_processed_folder')}
                            help={t('admin.email.fields.imap_processed_folder_help')}
                            error={form.errors.imap_processed_folder}
                        >
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.imap_processed_folder}
                                    className="font-mono"
                                    onChange={(event) => form.setData('imap_processed_folder', event.target.value)}
                                />
                            )}
                        </Field>

                        <Toggle
                            checked={form.data.imap_validate_cert}
                            onChange={(value) => form.setData('imap_validate_cert', value)}
                            label={t('admin.email.fields.imap_validate_cert')}
                        />

                        <Toggle
                            checked={form.data.imap_delete_after_processing}
                            onChange={(value) => form.setData('imap_delete_after_processing', value)}
                            label={t('admin.email.fields.imap_delete_after_processing')}
                        />

                        {section(t('admin.email.sections.routing'))}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('admin.email.fields.queue')} error={form.errors.queue_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.queue_id}
                                        onChange={(event) => form.setData('queue_id', event.target.value)}
                                    >
                                        <option value="">{t('common.labels.none')}</option>
                                        {options.queues.map((queue) => (
                                            <option key={queue.id} value={queue.id}>
                                                {queue.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.team')} error={form.errors.team_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.team_id}
                                        onChange={(event) => form.setData('team_id', event.target.value)}
                                    >
                                        <option value="">{t('common.labels.none')}</option>
                                        {options.teams.map((team) => (
                                            <option key={team.id} value={team.id}>
                                                {team.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.request_type')} error={form.errors.request_type_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.request_type_id}
                                        onChange={(event) => form.setData('request_type_id', event.target.value)}
                                    >
                                        <option value="">{t('common.labels.none')}</option>
                                        {options.requestTypes.map((type) => (
                                            <option key={type.id} value={type.id}>
                                                {type.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('admin.email.fields.priority')} error={form.errors.priority_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.priority_id}
                                        onChange={(event) => form.setData('priority_id', event.target.value)}
                                    >
                                        <option value="">{t('common.labels.default')}</option>
                                        {options.priorities.map((priority) => (
                                            <option key={priority.id} value={priority.id}>
                                                {priority.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        </div>

                        <Toggle
                            checked={form.data.auto_provision_requesters}
                            onChange={(value) => form.setData('auto_provision_requesters', value)}
                            label={t('admin.email.fields.auto_provision')}
                            description={t('admin.email.fields.auto_provision_help')}
                        />

                        <Field
                            label={t('admin.email.fields.ignore_senders')}
                            help={t('admin.email.fields.ignore_senders_help')}
                            error={form.errors.ignore_senders}
                        >
                            {(props) => (
                                <Textarea
                                    {...props}
                                    rows={3}
                                    className="font-mono text-xs"
                                    value={form.data.ignore_senders.join('\n')}
                                    placeholder={'no-reply@*\n*@example.org'}
                                    onChange={(event) =>
                                        form.setData(
                                            'ignore_senders',
                                            event.target.value
                                                .split('\n')
                                                .map((line) => line.trim())
                                                .filter(Boolean),
                                        )
                                    }
                                />
                            )}
                        </Field>
                    </div>
                ) : null}

                <Toggle
                    checked={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                    label={t('admin.email.fields.is_active')}
                />
            </div>
        </Modal>
    );
}

function TemplateDialog({
    template,
    templateKey,
    channels,
    locales,
    placeholders,
    onClose,
}: {
    template: Template | null;
    templateKey: string;
    channels: Channel[];
    locales: string[];
    placeholders: string[];
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const isEdit = template !== null;

    const form = useForm({
        key: templateKey,
        email_channel_id: template?.email_channel_id ? String(template.email_channel_id) : '',
        locale: template?.locale ?? locales[0] ?? 'en',
        subject: template?.subject ?? '',
        body: template?.body ?? '',
        is_active: template?.is_active ?? true,
    });

    const insert = (placeholder: string) => {
        form.setData('body', `${form.data.body}{{ ${placeholder} }}`);
    };

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={t(`admin.email.template_labels.${templateKey}`)}
            footer={
                <>
                    {isEdit ? (
                        <Button
                            variant="danger"
                            className="mr-auto"
                            onClick={() =>
                                router.delete(`/admin/email/templates/${template.id}`, {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                })
                            }
                        >
                            {t('common.actions.reset')}
                        </Button>
                    ) : null}
                    <Button variant="secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() =>
                            isEdit
                                ? form.put(`/admin/email/templates/${template.id}`, {
                                      preserveScroll: true,
                                      onSuccess: onClose,
                                  })
                                : form.post('/admin/email/templates', { preserveScroll: true, onSuccess: onClose })
                        }
                    >
                        {t('common.actions.save')}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('admin.email.templates.channel')}>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.email_channel_id}
                                onChange={(event) => form.setData('email_channel_id', event.target.value)}
                            >
                                <option value="">{t('admin.email.templates.all_channels')}</option>
                                {channels.map((channel) => (
                                    <option key={channel.id} value={channel.id}>
                                        {channel.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label={t('admin.email.templates.locale')} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={form.data.locale}
                                onChange={(event) => form.setData('locale', event.target.value)}
                            >
                                {locales.map((code) => (
                                    <option key={code} value={code}>
                                        {code.toUpperCase()}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </div>

                <Field label={t('admin.email.templates.subject')} error={form.errors.subject} required>
                    {(props) => (
                        <TextInput
                            {...props}
                            value={form.data.subject}
                            onChange={(event) => form.setData('subject', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('admin.email.templates.body')} error={form.errors.body} required>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={10}
                            value={form.data.body}
                            className="font-mono text-xs"
                            onChange={(event) => form.setData('body', event.target.value)}
                        />
                    )}
                </Field>

                <div>
                    <p className="text-sm font-medium text-slate-700">{t('admin.email.templates.placeholders')}</p>
                    <p className="mb-1.5 text-xs text-slate-500">{t('admin.email.templates.placeholders_help')}</p>
                    <div className="flex flex-wrap gap-1">
                        {placeholders.map((placeholder) => (
                            <button
                                key={placeholder}
                                type="button"
                                onClick={() => insert(placeholder)}
                                className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600 hover:bg-slate-200"
                            >
                                {placeholder}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <IconAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <p>{t('admin.email.templates.description')}</p>
                </div>
            </div>
        </Modal>
    );
}
