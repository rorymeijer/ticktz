import { IconLock, IconMail, IconPaperclip } from '@/Components/Icons';
import { Avatar, Badge } from '@/Components/UI';
import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { formatDateTime } from '@/lib/datetime';
import type { AttachmentSummary, TimelineItem } from '@/types/tickets';
import { RichText } from '@/Components/RichText/RichText';

/**
 * The ticket conversation: replies, internal notes and system events in one
 * chronological thread.
 *
 * Internal notes are visually unmistakable — an amber frame and a lock — because
 * the cost of an agent mistaking one for a public reply is a note going out to
 * the customer.
 */
export function Timeline({ items }: { items: TimelineItem[] }) {
    const { t, locale } = useTranslations();

    if (items.length === 0) {
        return <p className="px-5 py-8 text-center text-sm text-slate-500">{t('tickets.timeline.no_activity')}</p>;
    }

    return (
        <ol className="divide-y divide-slate-100">
            {items.map((item) =>
                item.type === 'event' ? (
                    <li key={item.id} className="flex flex-wrap items-baseline gap-x-2 px-5 py-2.5 text-sm text-slate-500">
                        <span className="font-medium text-slate-700">{item.actor.name}</span>
                        <span>{t(`tickets.timeline.events.${item.event}`)}</span>
                        <time className="ml-auto text-xs text-slate-500" dateTime={item.created_at ?? undefined}>
                            {formatDateTime(item.created_at, locale)}
                        </time>
                    </li>
                ) : (
                    <li
                        key={item.id}
                        className={cn('px-5 py-4', item.is_internal && 'border-l-2 border-amber-400 bg-amber-50/50')}
                    >
                        <div className="flex items-start gap-3">
                            <Avatar
                                name={item.author.name}
                                initials={item.author.initials ?? item.author.name.slice(0, 1).toUpperCase()}
                                color={item.author.avatar_color ?? '#94a3b8'}
                                size="sm"
                            />

                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span className="text-sm font-medium text-slate-900">{item.author.name}</span>

                                    {item.is_internal ? (
                                        <Badge tone="amber">
                                            <IconLock className="h-3 w-3" />
                                            {t('tickets.timeline.internal_note')}
                                        </Badge>
                                    ) : null}

                                    {item.source === 'email' ? (
                                        <Badge tone="blue">
                                            <IconMail className="h-3 w-3" />
                                            {t('tickets.source.email')}
                                        </Badge>
                                    ) : null}

                                    {item.edited_at ? (
                                        <span className="text-xs text-slate-500">({t('tickets.timeline.edited')})</span>
                                    ) : null}

                                    <time
                                        className="ml-auto text-xs text-slate-500"
                                        dateTime={item.created_at ?? undefined}
                                    >
                                        {formatDateTime(item.created_at, locale)}
                                    </time>
                                </div>

                                <RichText html={item.body} className="mt-1.5 break-words" />

                                {item.attachments.length > 0 ? <AttachmentList files={item.attachments} /> : null}
                            </div>
                        </div>
                    </li>
                ),
            )}
        </ol>
    );
}

export function AttachmentList({ files }: { files: AttachmentSummary[] }) {
    return (
        <ul className="mt-3 flex flex-wrap gap-2">
            {files.map((file) => (
                <li key={file.id}>
                    <a
                        href={file.url}
                        className="inline-flex max-w-64 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 hover:border-brand-300 hover:text-brand-700"
                    >
                        <IconPaperclip className="h-3.5 w-3.5 shrink-0 text-slate-500" />
                        <span className="truncate">{file.name}</span>
                        <span className="shrink-0 text-slate-500">{formatBytes(file.size)}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} kB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
