import { RichText } from '@/Components/RichText/RichText';

/**
 * An article body. Kept as its own name because the knowledge base reads
 * better for it; all of the work — and the sanitiser guarantee that makes it
 * safe — is {@link RichText}'s.
 */
export function ArticleBody({ html }: { html: string }) {
    return <RichText html={html} variant="article" />;
}
