/**
 * Renders an article.
 *
 * `dangerouslySetInnerHTML` is used deliberately and is safe here for one
 * reason only: the body was run through {@link ArticleSanitizer} on the way
 * into the database, so what is stored is already an allowlisted subset of
 * HTML. Nothing on this side of the wire is what keeps it safe — if the
 * sanitiser ever stops being the single write path, this is the component that
 * becomes a stored-XSS hole, which is why the guarantee is written down here
 * as well as there.
 */
export function ArticleBody({ html }: { html: string }) {
    return <div className="ticktz-prose ticktz-article" dangerouslySetInnerHTML={{ __html: html }} />;
}
