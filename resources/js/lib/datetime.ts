/**
 * Date helpers. Everything the server sends is ISO-8601 in UTC; rendering is
 * done in the viewer's own locale and timezone.
 */

export function formatDateTime(value: string | null | undefined, locale: string): string {
    if (!value) return '—';

    return new Date(value).toLocaleString(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatDate(value: string | null | undefined, locale: string): string {
    if (!value) return '—';

    return new Date(value).toLocaleDateString(locale, { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * "3 minutes ago" style output, driven by the caller's translator so the
 * strings stay in the language files.
 */
export function relativeTime(
    value: string | null | undefined,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (!value) return '—';

    const seconds = Math.floor((Date.now() - new Date(value).getTime()) / 1000);

    if (seconds < 60) return t('common.time.just_now');
    if (seconds < 3600) return t('common.time.minutes_ago', { count: Math.floor(seconds / 60) });
    if (seconds < 86400) return t('common.time.hours_ago', { count: Math.floor(seconds / 3600) });

    return t('common.time.days_ago', { count: Math.floor(seconds / 86400) });
}

/**
 * A deadline, in words: "2d 4h remaining" or "overdue by 3h".
 *
 * `relativeTime` cannot do this — it measures how long ago something was, so
 * every future moment comes back as "just now", which on a due date reads as
 * "answer it immediately" when the truth is "you have two days".
 */
export function deadline(
    value: string | null | undefined,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (!value) return '—';

    const seconds = (new Date(value).getTime() - Date.now()) / 1000;

    return seconds < 0
        ? t('common.time.overdue_by', { duration: formatDuration(seconds) })
        : t('common.time.remaining', { duration: formatDuration(seconds) });
}

/**
 * Compact duration such as "2d 4h" or "12m". Negative input is rendered
 * without a sign — callers decide how to label an overrun.
 */
export function formatDuration(seconds: number): string {
    const total = Math.abs(Math.round(seconds));
    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (days > 0) return hours > 0 ? `${days}d ${hours}h` : `${days}d`;
    if (hours > 0) return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;

    return `${minutes}m`;
}
