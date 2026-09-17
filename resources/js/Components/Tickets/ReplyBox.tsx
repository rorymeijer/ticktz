import { useForm } from '@inertiajs/react';
import { useRef, useState, type FormEventHandler } from 'react';

import { IconLock, IconPaperclip, IconX } from '@/Components/Icons';
import { RichTextEditor } from '@/Components/RichText/RichTextEditor';
import { Button } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { isBlankHtml } from '@/lib/richtext';

/**
 * Reply / internal note composer.
 *
 * The two modes are separate tabs with different colours rather than a
 * checkbox: "is this going to the customer?" must be answerable at a glance,
 * not by reading a label.
 */
export function ReplyBox({
    ticketKey,
    canReply,
    canNote,
}: {
    ticketKey: string;
    canReply: boolean;
    canNote: boolean;
}) {
    const { t } = useTranslations();
    const [internal, setInternal] = useState(!canReply && canNote);
    const fileInput = useRef<HTMLInputElement>(null);

    const form = useForm<{ body: string; is_internal: boolean; attachments: File[] }>({
        body: '',
        is_internal: !canReply && canNote,
        attachments: [],
    });

    if (!canReply && !canNote) {
        return null;
    }

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({ ...data, is_internal: internal }));
        form.post(`/agent/tickets/${ticketKey}/comments`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset('body', 'attachments');
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    const tab = (value: boolean, label: string, icon?: React.ReactNode) => (
        <button
            type="button"
            onClick={() => setInternal(value)}
            aria-pressed={internal === value}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                internal === value
                    ? value
                        ? 'border-amber-500 text-amber-700'
                        : 'border-brand-600 text-brand-700'
                    : 'border-transparent text-slate-500 hover:text-slate-800',
            )}
        >
            {icon}
            {label}
        </button>
    );

    return (
        <form
            onSubmit={submit}
            className={cn(
                'rounded-xl border bg-white shadow-card transition-colors',
                internal ? 'border-amber-300' : 'border-slate-200',
            )}
        >
            <div className="flex gap-1 border-b border-slate-200 px-3">
                {canReply ? tab(false, t('tickets.actions.reply')) : null}
                {canNote ? tab(true, t('tickets.actions.internal_note'), <IconLock className="h-3.5 w-3.5" />) : null}
            </div>

            <div className="p-3">
                <RichTextEditor
                    /*
                     * Keyed on the tab so switching between a reply and an
                     * internal note starts a fresh editor rather than carrying
                     * the draft across. Sending the customer a half-written
                     * internal note is the mistake this prevents.
                     */
                    key={internal ? 'note' : 'reply'}
                    value={form.data.body}
                    onChange={(html) => form.setData('body', html)}
                    label={internal ? t('tickets.actions.internal_note') : t('tickets.actions.reply')}
                    placeholder={internal ? t('tickets.placeholders.note') : t('tickets.placeholders.reply')}
                    invalid={Boolean(form.errors.body)}
                    minHeight={internal ? '6rem' : '8rem'}
                    className={cn(internal && 'bg-amber-50/40')}
                />

                {form.errors.body ? <p className="mt-1 text-xs text-red-600">{form.errors.body}</p> : null}

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

                <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
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

                    <Button
                        type="submit"
                        disabled={form.processing || isBlankHtml(form.data.body)}
                        variant={internal ? 'secondary' : 'primary'}
                    >
                        {internal ? t('tickets.actions.save_note') : t('tickets.actions.send_reply')}
                    </Button>
                </div>
            </div>
        </form>
    );
}
