import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { cn } from '@/lib/cn';
import type { SharedProps } from '@/types';

/**
 * Session flash messages rendered as dismissible toasts. Mounted once per
 * layout; Inertia refreshes the `flash` prop on every visit.
 */
export function FlashMessages() {
    const { flash } = usePage<SharedProps>().props;
    const [dismissed, setDismissed] = useState<string[]>([]);

    const messages = (['success', 'error', 'info'] as const)
        .map((tone) => ({ tone, message: flash?.[tone] }))
        .filter((entry): entry is { tone: 'success' | 'error' | 'info'; message: string } => Boolean(entry.message));

    useEffect(() => {
        setDismissed([]);
    }, [flash]);

    const visible = messages.filter((entry) => !dismissed.includes(entry.message));

    if (visible.length === 0) {
        return null;
    }

    const tones = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        error: 'border-red-200 bg-red-50 text-red-800',
        info: 'border-sky-200 bg-sky-50 text-sky-800',
    };

    return (
        <div className="pointer-events-none fixed inset-x-0 top-3 z-50 flex flex-col items-center gap-2 px-4" aria-live="polite">
            {visible.map((entry) => (
                <div
                    key={entry.message}
                    className={cn(
                        'pointer-events-auto flex w-full max-w-md animate-fade-in items-start gap-3 rounded-lg border px-4 py-2.5 text-sm shadow-popover',
                        tones[entry.tone],
                    )}
                    role={entry.tone === 'error' ? 'alert' : 'status'}
                >
                    <span className="flex-1">{entry.message}</span>
                    <button
                        type="button"
                        onClick={() => setDismissed((current) => [...current, entry.message])}
                        className="shrink-0 rounded p-0.5 text-current opacity-60 hover:opacity-100"
                        aria-label="Dismiss"
                    >
                        <svg viewBox="0 0 20 20" className="h-4 w-4" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>
            ))}
        </div>
    );
}
