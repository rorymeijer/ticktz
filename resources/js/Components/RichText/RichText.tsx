import { cn } from '@/lib/cn';

/**
 * Renders stored rich text.
 *
 * `dangerouslySetInnerHTML` is used deliberately and is safe here for one
 * reason only: every value that reaches this component was run through
 * `RichTextSanitizer` on the way into the database, so what is stored is
 * already an allowlisted subset of HTML. Nothing on this side of the wire is
 * what keeps it safe — if the sanitiser ever stops being the single write
 * path, this is the component that becomes a stored-XSS hole, which is why the
 * guarantee is written down here as well as there.
 *
 * `article` is the wider styling for knowledge base pages; the default is the
 * message styling a comment, description or note gets.
 */
export function RichText({
    html,
    variant = 'message',
    className,
}: {
    html: string | null | undefined;
    variant?: 'message' | 'article';
    className?: string;
}) {
    if (!html) {
        return null;
    }

    return (
        <div
            className={cn('ticktz-prose', variant === 'article' && 'ticktz-article', className)}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
