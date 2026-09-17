import { useForm } from '@inertiajs/react';
import { useRef, type FormEventHandler } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { IconPaperclip, IconX } from '@/Components/Icons';
import {
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    CardHeader,
    CheckboxGroup,
    Field,
    PageHeader,
    Select,
    TextInput,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { TicketOptions } from '@/types/tickets';
import { RichTextField } from '@/Components/RichText/RichTextField';

export default function TicketCreate({ options }: { options: TicketOptions }) {
    const { t } = useTranslations();
    const fileInput = useRef<HTMLInputElement>(null);

    const form = useForm<{
        subject: string;
        description: string;
        requester_id: string;
        priority_id: string;
        team_id: string;
        assignee_id: string;
        label_ids: number[];
        attachments: File[];
    }>({
        subject: '',
        description: '',
        requester_id: '',
        priority_id: String(options.priorities.find((priority) => priority.level === 3)?.id ?? options.priorities[0]?.id ?? ''),
        team_id: '',
        assignee_id: '',
        label_ids: [],
        attachments: [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post('/agent/tickets', { forceFormData: true });
    };

    return (
        <AppLayout title={t('tickets.create')}>
            <PageHeader
                title={t('tickets.create')}
                actions={
                    <ButtonLink href="/agent/tickets" variant="secondary" size="sm">
                        {t('common.actions.back')}
                    </ButtonLink>
                }
            />

            <form onSubmit={submit} className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <Card>
                    <CardBody className="space-y-4">
                        <Field label={t('tickets.fields.subject')} error={form.errors.subject} required>
                            {(props) => (
                                <TextInput
                                    {...props}
                                    value={form.data.subject}
                                    required
                                    autoFocus
                                    maxLength={500}
                                    placeholder={t('tickets.placeholders.subject')}
                                    onChange={(event) => form.setData('subject', event.target.value)}
                                />
                            )}
                        </Field>

                        <RichTextField
                            label={t('tickets.fields.description')}
                            error={form.errors.description}
                            value={form.data.description}
                            onChange={(html) => form.setData('description', html)}
                            placeholder={t('tickets.placeholders.description')}
                            minHeight="14rem"
                        />

                        <div>
                            <label className="inline-flex cursor-pointer items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900">
                                <IconPaperclip className="h-4 w-4" />
                                {t('tickets.actions.attach')}
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

                            {form.data.attachments.length > 0 ? (
                                <ul className="mt-2 flex flex-wrap gap-1.5">
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
                        </div>
                    </CardBody>
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title={t('tickets.detail.properties')} />
                        <CardBody className="space-y-4">
                            <Field label={t('tickets.fields.requester')} error={form.errors.requester_id} required>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.requester_id}
                                        required
                                        onChange={(event) => form.setData('requester_id', event.target.value)}
                                    >
                                        <option value="">{t('common.actions.select')}…</option>
                                        {(options.requesters ?? []).map((person) => (
                                            <option key={person.id} value={person.id}>
                                                {person.name} — {person.email}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('tickets.fields.priority')} error={form.errors.priority_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.priority_id}
                                        onChange={(event) => form.setData('priority_id', event.target.value)}
                                    >
                                        {options.priorities.map((priority) => (
                                            <option key={priority.id} value={priority.id}>
                                                {priority.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>

                            <Field label={t('tickets.fields.team')} error={form.errors.team_id}>
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

                            <Field label={t('tickets.fields.assignee')} error={form.errors.assignee_id}>
                                {(props) => (
                                    <Select
                                        {...props}
                                        value={form.data.assignee_id}
                                        onChange={(event) => form.setData('assignee_id', event.target.value)}
                                    >
                                        <option value="">{t('common.labels.unassigned')}</option>
                                        {(options.assignees ?? []).map((agent) => (
                                            <option key={agent.id} value={agent.id}>
                                                {agent.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </Field>
                        </CardBody>
                    </Card>

                    {options.labels.length > 0 ? (
                        <Card>
                            <CardHeader title={t('tickets.fields.labels')} />
                            <CardBody>
                                <CheckboxGroup
                                    options={options.labels.map((label) => ({ value: label.id, label: label.name }))}
                                    selected={form.data.label_ids}
                                    onChange={(values) => form.setData('label_ids', values)}
                                    maxHeight="max-h-48"
                                />
                            </CardBody>
                        </Card>
                    ) : null}

                    <Card>
                        <CardFooter>
                            <ButtonLink href="/agent/tickets" variant="secondary">
                                {t('common.actions.cancel')}
                            </ButtonLink>
                            <Button type="submit" disabled={form.processing}>
                                {t('common.actions.create')}
                            </Button>
                        </CardFooter>
                    </Card>
                </div>
            </form>
        </AppLayout>
    );
}
