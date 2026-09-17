import type { ReactNode } from 'react';

/**
 * Renders GitHub release notes.
 *
 * Release notes are the one piece of content in Ticktz that comes from outside
 * the instance, and this never renders them as markup: it walks the Markdown
 * and returns React elements. No HTML string is ever built, and React escapes
 * the text it puts in them — so there is nothing for a release note to inject,
 * whatever it contains, and that holds even if somebody points the update check
 * at a repository they do not control.
 *
 * Which is also why this handles a deliberately small subset. Headings, bold,
 * italic, code and list items cover what a changelog looks like. Anything else
 * — a raw `<div>`, an image, a link with a title — is left as the text it was
 * written as, which is a worse-looking line and never a surprising one.
 */
type Block =
    | { kind: 'heading'; text: string }
    | { kind: 'paragraph'; text: string }
    | { kind: 'list'; items: string[] };

export function parseReleaseNotes(markdown: string): Block[] {
    const blocks: Block[] = [];
    let paragraph: string[] = [];
    let list: string[] = [];

    const flush = () => {
        if (paragraph.length > 0) {
            blocks.push({ kind: 'paragraph', text: paragraph.join(' ') });
            paragraph = [];
        }
        if (list.length > 0) {
            blocks.push({ kind: 'list', items: list });
            list = [];
        }
    };

    for (const raw of markdown.replace(/\r\n/g, '\n').split('\n')) {
        const line = raw.trim();

        if (line === '') {
            flush();
            continue;
        }

        const heading = /^#{1,6}\s+(.*)$/.exec(line);
        if (heading) {
            flush();
            blocks.push({ kind: 'heading', text: heading[1] });
            continue;
        }

        const item = /^(?:[-*+]|\d+\.)\s+(.*)$/.exec(line);
        if (item) {
            if (paragraph.length > 0) flush();
            list.push(item[1]);
            continue;
        }

        if (list.length > 0) {
            // A continuation of the last item rather than a new paragraph:
            // wrapped bullets are how most changelogs are written.
            list[list.length - 1] += ' ' + line;
            continue;
        }

        paragraph.push(line);
    }

    flush();

    return blocks;
}

/**
 * The inline markers, in one pass.
 *
 * Returns elements, never a string of HTML. `*` is matched non-greedily and
 * across a single line only, so an unclosed marker stays the literal character
 * it was typed as instead of swallowing the rest of the note.
 */
export function formatInline(text: string, keyPrefix = ''): ReactNode[] {
    const nodes: ReactNode[] = [];
    const pattern = /(\*\*|__)(.+?)\1|(\*|_)(.+?)\3|`([^`]+)`/g;
    let last = 0;
    let match: RegExpExecArray | null;
    let index = 0;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) {
            nodes.push(text.slice(last, match.index));
        }

        const key = `${keyPrefix}-${index++}`;

        if (match[2] !== undefined) {
            nodes.push(<strong key={key}>{match[2]}</strong>);
        } else if (match[4] !== undefined) {
            nodes.push(<em key={key}>{match[4]}</em>);
        } else {
            nodes.push(
                <code key={key} className="rounded bg-slate-200/70 px-1 py-0.5 text-[0.85em]">
                    {match[5]}
                </code>,
            );
        }

        last = pattern.lastIndex;
    }

    if (last < text.length) {
        nodes.push(text.slice(last));
    }

    return nodes;
}

export function ReleaseNotes({ notes }: { notes: string }) {
    const blocks = parseReleaseNotes(notes);

    return (
        <div className="space-y-2 text-sm text-slate-700">
            {blocks.map((block, i) => {
                if (block.kind === 'heading') {
                    return (
                        <p key={i} className="font-semibold text-slate-900">
                            {formatInline(block.text, `h${i}`)}
                        </p>
                    );
                }

                if (block.kind === 'list') {
                    return (
                        <ul key={i} className="list-disc space-y-1 pl-5">
                            {block.items.map((item, j) => (
                                <li key={j}>{formatInline(item, `l${i}-${j}`)}</li>
                            ))}
                        </ul>
                    );
                }

                return <p key={i}>{formatInline(block.text, `p${i}`)}</p>;
            })}
        </div>
    );
}
