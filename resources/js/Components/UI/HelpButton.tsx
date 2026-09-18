import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Modal } from '@/Components/UI/Modal';
import { RichText } from '@/Components/RichText/RichText';
import { useTranslations } from '@/hooks/useTranslations';

interface Chapter {
    slug: string;
    title: string;
    html: string;
}

/**
 * The `?` that explains the screen you are on.
 *
 * A manual you have to go and find is a manual nobody reads at the moment they
 * need it. This one comes to the screen: it asks which chapter covers the page
 * component it is sitting in, and opens it without navigating away from what
 * somebody is stuck on.
 *
 * It does not render at all on a page with no chapter. A `?` that opens and
 * then admits there is nothing written is worse than no `?` — the list of
 * covered pages is shared with every response, so the button knows before
 * anybody clicks.
 */
export function HelpButton() {
    const { t } = useTranslations();
    const page = usePage();
    const component = page.component;
    const covered = page.props.help?.pages ?? [];

    const [open, setOpen] = useState(false);
    const [chapter, setChapter] = useState<Chapter | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) return;

        const controller = new AbortController();

        setFailed(false);

        fetch(`/help?page=${encodeURIComponent(component)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) throw new Error(String(response.status));

                return response.json();
            })
            .then((data: { chapter: Chapter | null }) => setChapter(data.chapter))
            .catch((error: unknown) => {
                if (error instanceof DOMException && error.name === 'AbortError') return;

                setFailed(true);
            });

        return () => controller.abort();
    }, [open, component]);

    if (!covered.includes(component)) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label={t('common.manual.explain_this_page')}
                title={t('common.manual.explain_this_page')}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-slate-300 text-sm font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900"
            >
                ?
            </button>

            {open ? (
                <Modal
                    open
                    onClose={() => setOpen(false)}
                    size="lg"
                    title={chapter?.title ?? t('common.manual.title')}
                    footer={
                        <a
                            href="/manual"
                            className="text-sm font-medium text-brand-700 hover:text-brand-900"
                        >
                            {t('common.manual.open_full')}
                        </a>
                    }
                >
                    {failed ? (
                        <p className="text-sm text-red-600">{t('common.manual.failed')}</p>
                    ) : chapter ? (
                        <RichText html={chapter.html} className="break-words" />
                    ) : (
                        <p className="text-sm text-slate-500">{t('common.manual.loading')}</p>
                    )}
                </Modal>
            ) : null}
        </>
    );
}
