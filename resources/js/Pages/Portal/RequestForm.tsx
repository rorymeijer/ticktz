import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEventHandler } from 'react';

import PortalLayout from '@/Layouts/PortalLayout';
import { ArticleSuggestions } from '@/Components/Portal/ArticleSuggestions';
import { DynamicField, type FieldDefinition, type FieldValue } from '@/Components/Portal/DynamicField';
import { IconPaperclip, IconX } from '@/Components/Icons';
import {
    Button,
    ButtonLink,
    Card,
    CardBody,
    CardFooter,
    Field,
    Select,
    TextInput,
    Textarea,
} from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import type { PrioritySummary } from '@/types/tickets';

interface RequestTypePayload {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    instructions: string | null;
    subject_template: string | null;
    allow_priority_choice: boolean;
    fields: FieldDefinition[];
    category: { name: string } | null;
}

export default function RequestForm({
    requestType,
    priorities,
}: {
    requestType: RequestTypePayload;
    priorities: PrioritySummary[];
}) {
    const { t } = useTranslations();
    const fileInput = useRef<HTMLInputElement>(null);

    // Seed the answers from each field's configured default.
    const [fields, setFields] = useState<Record<string, FieldValue>>(() =>
        Object.fromEntries(
            requestType.fields.map((field) => [
                field.key,
                field.type === 'multiselect'
                    ? []
                    : field.type === 'checkbox'
                      ? field.default_value === '1'
                      : (field.default_value ?? ''),
            ]),
        ),
    );

    const form = useForm<{
        subject: string;
        description: string;
        priority_id: string;
        fields: Record<string, FieldValue>;
        attachments: File[];
    }>({
        subject: '',
        description: '',
        priority_id: '',
        fields: {},
        attachments: [],
    });

    const setField = (key: string, value: FieldValue) => {
        setFields((current) => ({ ...current, [key]: value }));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({ ...data, fields }));
        form.post(`/portal/new/${requestType.slug}`, { forceFormData: true });
    };

    // A template renders the subject for the requester, so we do not ask twice.
    const asksForSubject = !requestType.subject_template;

    // What we look for an answer with. The subject is the better signal, but a
    // templated request type does not have one, and the description is then
    // the only thing the requester has written.
    const suggestionTerm = asksForSubject ? form.data.subject : form.data.description;

    return (
        <PortalLayout title={requestType.name}>
            <div className="mx-auto max-w-2xl">
                <div className="mb-5">
                    {requestType.category ? (
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {requestType.category.name}
                        </p>
                    ) : null}
                    <h2 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{requestType.name}</h2>
                    {requestType.description ? (
                        <p className="mt-1.5 text-sm text-slate-600">{requestType.description}</p>
                    ) : null}
                </div>

                {requestType.instructions ? (
                    <div className="mb-5 whitespace-pre-wrap rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900">
                        {requestType.instructions}
                    </div>
                ) : null}

                <form onSubmit={submit}>
                    <Card>
                        <CardBody className="space-y-4">
                            {asksForSubject ? (
                                <Field label={t('portal.form.subject')} error={form.errors.subject} required>
                                    {(props) => (
                                        <TextInput
                                            {...props}
                                            value={form.data.subject}
                                            required
                                            autoFocus
                                            maxLength={500}
                                            placeholder={t('portal.form.subject_placeholder')}
                                            onChange={(event) => form.setData('subject', event.target.value)}
                                        />
                                    )}
                                </Field>
                            ) : null}

                            <ArticleSuggestions subject={suggestionTerm} />

                            {requestType.fields.map((field) => (
                                <DynamicField
                                    key={field.key}
                                    field={field}
                                    value={fields[field.key] ?? null}
                                    error={form.errors[`fields.${field.key}` as keyof typeof form.errors] as string}
                                    onChange={(value) => setField(field.key, value)}
                                />
                            ))}

                            {requestType.allow_priority_choice && priorities.length > 0 ? (
                                <Field label={t('portal.form.priority')} error={form.errors.priority_id}>
                                    {(props) => (
                                        <Select
                                            {...props}
                                            value={form.data.priority_id}
                                            onChange={(event) => form.setData('priority_id', event.target.value)}
                                        >
                                            <option value="">{t('portal.form.select_placeholder')}</option>
                                            {priorities.map((priority) => (
                                                <option key={priority.id} value={priority.id}>
                                                    {priority.name}
                                                </option>
                                            ))}
                                        </Select>
                                    )}
                                </Field>
                            ) : null}

                            <Field label={t('portal.form.description')} error={form.errors.description}>
                                {(props) => (
                                    <Textarea
                                        {...props}
                                        rows={6}
                                        value={form.data.description}
                                        placeholder={t('portal.form.description_placeholder')}
                                        onChange={(event) => form.setData('description', event.target.value)}
                                    />
                                )}
                            </Field>

                            <div>
                                <label className="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">
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
                                <p className="mt-0.5 text-xs text-slate-500">{t('portal.form.attachments_help')}</p>

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
                                                    className="text-slate-400 hover:text-slate-700"
                                                >
                                                    <IconX className="h-3 w-3" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>

                            <p className="text-xs text-slate-500">{t('portal.form.required_hint')}</p>
                        </CardBody>

                        <CardFooter>
                            <ButtonLink href="/portal" variant="secondary">
                                {t('common.actions.cancel')}
                            </ButtonLink>
                            <Button type="submit" size="lg" disabled={form.processing}>
                                {t('portal.form.submit')}
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </PortalLayout>
    );
}
