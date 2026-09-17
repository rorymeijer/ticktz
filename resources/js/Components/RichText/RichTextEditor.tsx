import Image from '@tiptap/extension-image';
import { Placeholder } from '@tiptap/extensions';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useCallback, useEffect, useId, useRef, useState } from 'react';

import { useTranslations } from '@/hooks/useTranslations';
import { cn } from '@/lib/cn';
import { editorValue, isBlankHtml, normaliseUrl } from '@/lib/richtext';
import {
    imageFilesIn,
    RichTextUploadError,
    uploadRichTextImage,
    type UploadFailure,
} from '@/lib/richTextUpload';

import { toolbarItems, type RichTextProfile, type ToolbarState } from './toolbar';

export type { RichTextProfile };

/**
 * The one rich text editor in Ticktz.
 *
 * Built on TipTap (ProseMirror) rather than a contenteditable of our own:
 * keeping a selection, an undo stack and paste normalisation correct across
 * browsers is a multi-year problem, and the alternative to a library here is
 * not "no library", it is a worse one.
 *
 * Two things are worth knowing before changing it:
 *
 * **Nothing here is a security boundary.** Whatever this produces is
 * sanitised server-side against the same named profile before it is stored. A
 * toolbar that offered more than the profile allows would not be a hole, it
 * would be a bug — the author would watch their formatting disappear on save.
 *
 * **The toolbar is a real toolbar.** One tab stop, arrow keys between the
 * buttons, `aria-pressed` on the toggles, Alt+F10 to reach it from the text
 * and Escape to get back. A row of buttons that each take a tab stop turns
 * "tab to the next field" into fourteen keystrokes.
 */
export function RichTextEditor({
    value,
    onChange,
    profile = 'basic',
    id,
    labelledBy,
    label,
    describedBy,
    invalid,
    placeholder,
    disabled,
    images = false,
    internal = false,
    minHeight = '10rem',
    className,
}: {
    value: string;
    onChange: (html: string) => void;
    profile?: RichTextProfile;
    id?: string;
    labelledBy?: string;
    label?: string;
    describedBy?: string;
    invalid?: boolean;
    placeholder?: string;
    disabled?: boolean;
    /**
     * Whether this field can hold images. Off by default and set only where
     * the server says the same — a field whose readership is not defined
     * cannot decide who may see a screenshot pasted into it.
     */
    images?: boolean;
    /** Pasting into an internal note; travels with the upload. */
    internal?: boolean;
    minHeight?: string;
    className?: string;
}) {
    const { t } = useTranslations();
    const generatedId = useId();
    const editorId = id ?? generatedId;
    const hintId = `${editorId}-hint`;

    const [linkOpen, setLinkOpen] = useState(false);
    const [altOpen, setAltOpen] = useState(false);
    /**
     * What the editor is doing with an image, announced politely.
     *
     * An upload that takes two seconds and then silently succeeds is fine to
     * look at and invisible to listen to, so the state is a message rather
     * than a spinner.
     */
    const [imageStatus, setImageStatus] = useState<'idle' | 'uploading' | UploadFailure>('idle');
    const filePicker = useRef<HTMLInputElement>(null);
    const editorRef = useRef<Editor | null>(null);

    const editor = useEditor(
        {
            editable: !disabled,
            extensions: [
                StarterKit.configure({
                    // The message profile is a message, not a document. These
                    // mirror the server profile exactly; see toolbar.ts.
                    heading: profile === 'article' ? { levels: [2, 3, 4] } : false,
                    codeBlock: profile === 'article' ? {} : false,
                    horizontalRule: profile === 'article' ? {} : false,
                    link: {
                        openOnClick: false,
                        autolink: true,
                        defaultProtocol: 'https',
                        protocols: ['http', 'https', 'mailto'],
                    },
                }),
                Placeholder.configure({ placeholder: placeholder ?? '' }),
                ...(images
                    ? [
                          Image.configure({
                              // Never base64. An inline data URI would put the
                              // whole file in the ticket body, past every size
                              // limit and out of reach of the policy that
                              // decides who may see it.
                              allowBase64: false,
                              HTMLAttributes: { loading: 'lazy' },
                          }),
                      ]
                    : []),
            ],
            content: value,
            editorProps: {
                /*
                 * Paste and drop both end up in the same place. Returning true
                 * tells ProseMirror we have taken the event, which is what
                 * stops a pasted screenshot also being inserted as whatever
                 * the clipboard's text flavour happened to be.
                 */
                handlePaste: (_view, event) => {
                    const files = images ? imageFilesIn(event.clipboardData?.items) : [];

                    if (files.length === 0) {
                        return false;
                    }

                    event.preventDefault();
                    void insertImages(files);

                    return true;
                },
                handleDrop: (_view, event) => {
                    const files = images ? imageFilesIn((event as DragEvent).dataTransfer?.files) : [];

                    if (files.length === 0) {
                        return false;
                    }

                    event.preventDefault();
                    void insertImages(files);

                    return true;
                },
                attributes: {
                    // ProseMirror's contenteditable is the text box. Naming it
                    // explicitly is what makes it announce as one field rather
                    // than as a group of paragraphs.
                    role: 'textbox',
                    'aria-multiline': 'true',
                    id: editorId,
                    class: 'ticktz-prose ticktz-editor focus:outline-none',
                    ...(labelledBy ? { 'aria-labelledby': labelledBy } : {}),
                    ...(label && !labelledBy ? { 'aria-label': label } : {}),
                    'aria-describedby': [describedBy, hintId].filter(Boolean).join(' '),
                    ...(invalid ? { 'aria-invalid': 'true' } : {}),
                },
            },
            onUpdate: ({ editor: instance }) => onChange(editorValue(instance.getHTML())),
        },
        [profile, disabled],
    );

    /*
     * Pull an outside change in — a form reset after a successful submit, a
     * draft restored, a canned response inserted. Guarded on the current
     * content: writing back on every render would move the caret to the start
     * on every keystroke.
     */
    useEffect(() => {
        if (!editor) {
            return;
        }

        const current = editor.getHTML();
        const incoming = value || '';

        if (incoming === current || (isBlankHtml(incoming) && isBlankHtml(current))) {
            return;
        }

        editor.commands.setContent(incoming, { emitUpdate: false });
    }, [editor, value]);

    editorRef.current = editor;

    /**
     * Upload each image and drop it into the document where the caret is.
     *
     * Sequential rather than parallel: three screenshots pasted at once should
     * land in the order they were pasted, and a rate limit that refuses the
     * third should not also have refused the second.
     *
     * `alt` starts as the file name. It is a poor description and a far better
     * starting point than nothing — a screen reader announcing
     * "Screenshot 2026-09-17.png" at least says an image is there, and the
     * author can replace it with the alt text box.
     */
    const insertImages = useCallback(
        async (files: File[]) => {
            setImageStatus('uploading');

            for (const file of files) {
                try {
                    const uploaded = await uploadRichTextImage(file, internal);

                    editorRef.current
                        ?.chain()
                        .focus()
                        .setImage({ src: uploaded.url, alt: uploaded.name })
                        .run();

                    setImageStatus('idle');
                } catch (error) {
                    setImageStatus(error instanceof RichTextUploadError ? error.kind : 'failed');

                    return;
                }
            }
        },
        [internal],
    );

    const focusToolbar = useCallback(() => {
        const first = document
            .getElementById(`${editorId}-toolbar`)
            ?.querySelector<HTMLElement>('[data-toolbar-item]:not([disabled])');

        first?.focus();
    }, [editorId]);

    if (!editor) {
        return null;
    }

    return (
        <div
            className={cn(
                'overflow-hidden rounded-lg border bg-white shadow-sm focus-within:ring-1',
                invalid
                    ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-500'
                    : 'border-slate-300 focus-within:border-brand-500 focus-within:ring-brand-500',
                disabled && 'bg-slate-50',
                className,
            )}
        >
            <Toolbar
                editor={editor}
                profile={profile}
                id={`${editorId}-toolbar`}
                controls={editorId}
                disabled={disabled}
                linkOpen={linkOpen}
                onLink={() => setLinkOpen(true)}
                onImage={images ? () => filePicker.current?.click() : null}
                onEscape={() => editor.commands.focus()}
            />

            {images ? (
                <input
                    ref={filePicker}
                    type="file"
                    accept="image/*"
                    multiple
                    className="sr-only"
                    aria-label={t('editor.image.add')}
                    onChange={(event) => {
                        void insertImages(Array.from(event.target.files ?? []));
                        // Cleared so picking the same file twice in a row
                        // still fires a change event.
                        event.target.value = '';
                    }}
                />
            ) : null}

            {linkOpen ? (
                <LinkRow
                    editor={editor}
                    onClose={() => {
                        setLinkOpen(false);
                        editor.commands.focus();
                    }}
                />
            ) : null}

            {altOpen ? (
                <AltTextRow
                    editor={editor}
                    onClose={() => {
                        setAltOpen(false);
                        editor.commands.focus();
                    }}
                />
            ) : null}

            <EditorContent
                editor={editor}
                className="px-3 py-2.5"
                style={{ minHeight }}
                onKeyDown={(event) => {
                    // The long-standing convention for reaching a toolbar from
                    // inside a text box, and the only way to reach this one
                    // without tabbing backwards past the field.
                    if (event.altKey && event.key === 'F10') {
                        event.preventDefault();
                        focusToolbar();
                    }
                }}
            />

            {images && editor.isActive('image') ? (
                <div className="border-t border-slate-200 bg-slate-50 px-2 py-1.5">
                    <button
                        type="button"
                        onClick={() => setAltOpen(true)}
                        className="rounded-md px-2 py-1 text-xs font-medium text-brand-700 hover:bg-slate-200"
                    >
                        {t('editor.image.alt')}
                    </button>
                </div>
            ) : null}

            {/*
             * Polite rather than assertive: an upload finishing is worth
             * knowing and not worth interrupting a sentence for. Always in the
             * DOM so the region exists before it has anything to say —
             * announcing from a node that has only just appeared is
             * unreliable in several screen readers.
             */}
            <p className="sr-only" role="status" aria-live="polite">
                {imageStatus === 'uploading' ? t('editor.image.uploading') : null}
                {imageStatus !== 'idle' && imageStatus !== 'uploading' ? t(`editor.image.${imageStatus}`) : null}
            </p>

            {imageStatus !== 'idle' && imageStatus !== 'uploading' ? (
                <p className="border-t border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700">
                    {t(`editor.image.${imageStatus}`)}
                </p>
            ) : null}

            <p id={hintId} className="sr-only">
                {t('editor.hint')}
            </p>
        </div>
    );
}

/**
 * The formatting toolbar: one tab stop, arrow keys inside.
 */
function Toolbar({
    editor,
    profile,
    id,
    controls,
    disabled,
    linkOpen,
    onLink,
    onImage,
    onEscape,
}: {
    editor: Editor;
    profile: RichTextProfile;
    id: string;
    controls: string;
    disabled?: boolean;
    linkOpen: boolean;
    onLink: () => void;
    onImage: (() => void) | null;
    onEscape: () => void;
}) {
    const { t } = useTranslations();
    const [focusIndex, setFocusIndex] = useState(0);
    const container = useRef<HTMLDivElement>(null);

    /*
     * Sampled through `useEditorState`, not read live while rendering.
     *
     * Since TipTap 3 the editor does not re-render its React tree on every
     * transaction — `shouldRerenderOnTransaction` defaults to false — so a
     * toolbar built from `editor.isActive()` calls draws itself once and then
     * shows whatever happened to be true when it mounted: bold never lights
     * up, undo stays greyed out for good. The selector re-renders only when
     * one of these values actually changes, which is what the old behaviour
     * should have been doing anyway.
     */
    const state = useEditorState({
        editor,
        selector: ({ editor: instance }): ToolbarState => ({
            bold: instance.isActive('bold'),
            italic: instance.isActive('italic'),
            underline: instance.isActive('underline'),
            strike: instance.isActive('strike'),
            code: instance.isActive('code'),
            bulletList: instance.isActive('bulletList'),
            orderedList: instance.isActive('orderedList'),
            blockquote: instance.isActive('blockquote'),
            codeBlock: instance.isActive('codeBlock'),
            link: instance.isActive('link'),
            heading: ([2, 3, 4] as const).find((level) => instance.isActive('heading', { level })) ?? null,
            canUndo: instance.can().undo(),
            canRedo: instance.can().redo(),
        }),
    });

    const items = toolbarItems(editor, profile, state, onLink, onImage);
    const stops = items.filter((item) => item.kind !== 'separator');

    const move = (delta: number) => {
        const next = (focusIndex + delta + stops.length) % stops.length;

        setFocusIndex(next);
        container.current
            ?.querySelectorAll<HTMLElement>('[data-toolbar-item]')
            ?.[next]?.focus();
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        switch (event.key) {
            case 'ArrowRight':
                event.preventDefault();
                move(1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                move(-1);
                break;
            case 'Home':
                event.preventDefault();
                setFocusIndex(0);
                container.current?.querySelector<HTMLElement>('[data-toolbar-item]')?.focus();
                break;
            case 'End': {
                event.preventDefault();
                const all = container.current?.querySelectorAll<HTMLElement>('[data-toolbar-item]');
                setFocusIndex(stops.length - 1);
                all?.[all.length - 1]?.focus();
                break;
            }
            case 'Escape':
                event.preventDefault();
                onEscape();
                break;
            default:
                break;
        }
    };

    let stopIndex = -1;

    return (
        <div
            ref={container}
            id={id}
            role="toolbar"
            aria-label={t('editor.toolbar')}
            aria-controls={controls}
            aria-orientation="horizontal"
            onKeyDown={onKeyDown}
            className="flex flex-wrap items-center gap-0.5 border-b border-slate-200 bg-slate-50 px-1.5 py-1"
        >
            {items.map((item) => {
                if (item.kind === 'separator') {
                    return <span key={item.id} aria-hidden="true" className="mx-1 h-5 w-px bg-slate-200" />;
                }

                stopIndex += 1;
                const tabIndex = stopIndex === focusIndex ? 0 : -1;

                if (item.kind === 'style') {
                    return (
                        <StyleSelect
                            key={item.id}
                            editor={editor}
                            heading={state.heading}
                            disabled={disabled}
                            tabIndex={tabIndex}
                            onFocus={() => setFocusIndex(0)}
                        />
                    );
                }

                const pressed = item.id === 'link' ? linkOpen || item.active : item.active;

                return (
                    <button
                        key={item.id}
                        type="button"
                        data-toolbar-item
                        tabIndex={tabIndex}
                        disabled={disabled || item.disabled}
                        aria-pressed={pressed === undefined ? undefined : pressed}
                        aria-label={t(item.labelKey)}
                        title={t(item.labelKey)}
                        onFocus={() => setFocusIndex(stops.findIndex((stop) => stop.id === item.id))}
                        onClick={item.run}
                        className={cn(
                            'rounded-md p-1.5 text-slate-600 transition-colors',
                            'hover:bg-slate-200 hover:text-slate-900',
                            'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-600',
                            'disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-transparent',
                            pressed && 'bg-slate-200 text-slate-900',
                        )}
                    >
                        <item.icon />
                    </button>
                );
            })}
        </div>
    );
}

/**
 * Heading level as a select rather than three toggle buttons: it is one tab
 * stop instead of three, it says what the current block *is* rather than
 * leaving the reader to infer it from which button is pressed, and it is the
 * control every word processor uses for this.
 */
function StyleSelect({
    editor,
    heading,
    disabled,
    tabIndex,
    onFocus,
}: {
    editor: Editor;
    heading: 2 | 3 | 4 | null;
    disabled?: boolean;
    tabIndex: number;
    onFocus: () => void;
}) {
    const { t } = useTranslations();

    return (
        <select
            data-toolbar-item
            tabIndex={tabIndex}
            disabled={disabled}
            aria-label={t('editor.style.label')}
            value={heading ? `h${heading}` : 'p'}
            onFocus={onFocus}
            onChange={(event) => {
                const next = event.target.value;

                if (next === 'p') {
                    editor.chain().focus().setParagraph().run();

                    return;
                }

                editor
                    .chain()
                    .focus()
                    .setHeading({ level: Number(next.slice(1)) as 2 | 3 | 4 })
                    .run();
            }}
            className={cn(
                'rounded-md border-0 bg-transparent py-1 pl-1.5 pr-7 text-xs font-medium text-slate-700',
                'hover:bg-slate-200 focus:ring-2 focus:ring-brand-500',
            )}
        >
            <option value="p">{t('editor.style.paragraph')}</option>
            <option value="h2">{t('editor.style.h2')}</option>
            <option value="h3">{t('editor.style.h3')}</option>
            <option value="h4">{t('editor.style.h4')}</option>
        </select>
    );
}

/**
 * The link box.
 *
 * Inline rather than `window.prompt`: a prompt cannot be styled, cannot be
 * translated, cannot show a validation message, and is blocked outright in
 * some browsers.
 */
function LinkRow({ editor, onClose }: { editor: Editor; onClose: () => void }) {
    const { t } = useTranslations();
    const inputId = useId();
    const errorId = `${inputId}-error`;
    const input = useRef<HTMLInputElement>(null);

    const [href, setHref] = useState(() => (editor.getAttributes('link').href as string | undefined) ?? '');
    const [error, setError] = useState(false);

    useEffect(() => {
        input.current?.focus();
        input.current?.select();
    }, []);

    const apply = () => {
        const url = normaliseUrl(href);

        if (!url) {
            setError(true);
            input.current?.focus();

            return;
        }

        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        onClose();
    };

    return (
        <div
            className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-2 py-2"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onClose();
                }
            }}
        >
            <label htmlFor={inputId} className="text-xs font-medium text-slate-700">
                {t('editor.link.url')}
            </label>
            <input
                ref={input}
                id={inputId}
                type="text"
                inputMode="url"
                value={href}
                placeholder={t('editor.link.placeholder')}
                aria-invalid={error || undefined}
                aria-describedby={error ? errorId : undefined}
                onChange={(event) => {
                    setHref(event.target.value);
                    setError(false);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        apply();
                    }
                }}
                className={cn(
                    'min-w-0 flex-1 rounded-md border px-2 py-1 text-xs text-slate-900 shadow-sm',
                    'focus:border-brand-500 focus:ring-brand-500',
                    error ? 'border-red-400' : 'border-slate-300',
                )}
            />
            <button
                type="button"
                onClick={apply}
                className="rounded-md bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700"
            >
                {t('editor.link.apply')}
            </button>
            <button
                type="button"
                onClick={onClose}
                className="rounded-md px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-slate-900"
            >
                {t('editor.link.cancel')}
            </button>
            {error ? (
                <p id={errorId} role="alert" className="w-full text-xs font-medium text-red-600">
                    {t('editor.link.invalid')}
                </p>
            ) : null}
        </div>
    );
}

/**
 * The alt text box.
 *
 * A pasted screenshot arrives with its file name as its description, which
 * says an image is there and nothing about what is in it. This is where that
 * gets fixed, and it exists because the alternative — shipping images with no
 * way to describe them — makes every ticket containing one unreadable to
 * somebody, and makes the accessibility gate a thing we pass rather than a
 * thing we mean.
 */
function AltTextRow({ editor, onClose }: { editor: Editor; onClose: () => void }) {
    const { t } = useTranslations();
    const inputId = useId();
    const helpId = `${inputId}-help`;
    const input = useRef<HTMLInputElement>(null);

    const [alt, setAlt] = useState(() => (editor.getAttributes('image').alt as string | undefined) ?? '');

    useEffect(() => {
        input.current?.focus();
        input.current?.select();
    }, []);

    const apply = () => {
        editor.chain().focus().updateAttributes('image', { alt: alt.trim() }).run();
        onClose();
    };

    return (
        <div
            className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-2 py-2"
            onKeyDown={(event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onClose();
                }
            }}
        >
            <label htmlFor={inputId} className="text-xs font-medium text-slate-700">
                {t('editor.image.alt')}
            </label>
            <input
                ref={input}
                id={inputId}
                type="text"
                value={alt}
                maxLength={250}
                aria-describedby={helpId}
                onChange={(event) => setAlt(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        apply();
                    }
                }}
                className="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-900 shadow-sm focus:border-brand-500 focus:ring-brand-500"
            />
            <button
                type="button"
                onClick={apply}
                className="rounded-md bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700"
            >
                {t('editor.image.alt_apply')}
            </button>
            <button
                type="button"
                onClick={onClose}
                className="rounded-md px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-slate-900"
            >
                {t('editor.link.cancel')}
            </button>
            <p id={helpId} className="w-full text-xs text-slate-500">
                {t('editor.image.alt_help')}
            </p>
        </div>
    );
}
