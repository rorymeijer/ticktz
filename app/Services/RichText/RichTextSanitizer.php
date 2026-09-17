<?php

declare(strict_types=1);

namespace App\Services\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Makes rich text safe to store, and readable once the markup is gone.
 *
 * Every field in Ticktz that a person writes prose into — an article, a ticket
 * description, a reply, an asset note — passes through here. Those are the
 * places where one person's input is rendered as markup in another person's
 * browser, which makes them the whole stored-XSS surface of the application.
 *
 * Three decisions worth stating:
 *
 * 1. **Sanitised on the way in, never on the way out.** Text that is safe in
 *    the database is safe everywhere it is rendered — the console, the portal,
 *    an e-mail, an export, a future report. Sanitising on render means
 *    remembering to do it at every call site, and one forgotten
 *    `dangerouslySetInnerHTML` is the whole game.
 * 2. **Symfony's sanitiser, not a hand-rolled one.** HTML sanitisation is full
 *    of mutation-XSS corners that look fine until they do not, and Symfony is
 *    already throughout this tree.
 * 3. **An allowlist that starts empty.** Deliberately *not*
 *    `allowStaticElements()`. That helper adds a broad default set — `<marquee>`
 *    and friends among them — and then the lists below would be adding to a
 *    default-allow config rather than being the allowlist. An empty config that
 *    only gains what is named is the one shape that cannot be wrong about an
 *    element nobody thought to consider.
 */
class RichTextSanitizer
{
    /**
     * The message vocabulary: what a ticket reply, a note or a description may
     * contain. No headings — a comment is not a document — no tables, and no
     * images, which would need somewhere to be stored.
     *
     * @var array<int, string>
     */
    private const BASIC = [
        'p', 'br', 'hr', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code', 'kbd', 'samp',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a',
    ];

    /**
     * The document vocabulary: the message one plus everything an article
     * needs to be a page rather than a paragraph.
     *
     * @var array<int, string>
     */
    private const ARTICLE = [
        ...self::BASIC,
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'small', 'var',
        'dl', 'dt', 'dd',
        'img', 'figure', 'figcaption',
    ];

    /**
     * Legacy and pasted-from-a-word-processor formatting, plus the structural
     * tags that belong to the page rather than to its content. These are
     * unwrapped rather than deleted, so somebody who pastes `<font>Read
     * this</font>` loses the font tag and keeps the sentence.
     *
     * Note the naming, which is worth reading twice: Symfony's `dropElement`
     * removes an element *and its content*, while `blockElement` removes only
     * the tag and keeps the text. This list needs the latter.
     *
     * @var array<int, string>
     */
    private const UNWRAPPED = [
        'font', 'center', 'marquee', 'blink', 'big', 'tt', 'strike', 'nobr',
        'section', 'article', 'header', 'footer', 'main', 'aside', 'nav',
    ];

    /**
     * Elements whose content goes with them. Anything here could execute, load
     * something, or collect a password, so keeping the text would be keeping
     * the payload.
     *
     * Unlisted elements are removed with their content too — this list is for
     * saying so out loud about the ones that matter.
     *
     * @var array<int, string>
     */
    private const REMOVED = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template',
        'form', 'input', 'button', 'select', 'option', 'textarea',
        'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'base', 'meta', 'link',
    ];

    /** @var array<string, HtmlSanitizer> */
    private array $sanitizers = [];

    /**
     * Clean a value for storage.
     *
     * Plain text is accepted and converted rather than refused: an API client
     * written against 1.0.0 sends a description with no markup in it at all,
     * and an IMAP reply arrives as text. Both should keep working, and both
     * should end up in the same shape as something typed into the editor.
     */
    public function clean(string $value, RichTextProfile $profile = RichTextProfile::Basic): string
    {
        if (! $this->looksLikeHtml($value)) {
            $value = $this->fromPlainText($value);
        }

        if ($profile === RichTextProfile::Basic) {
            $value = $this->demoteHeadings($value);
        }

        $clean = trim($this->sanitizer($profile)->sanitize($value));

        // An empty editor still posts `<p></p>`, and a body that sanitises down
        // to nothing but empty wrappers is empty. Storing the wrapper would
        // make `required` pass on a blank reply and make "has a description"
        // true for a ticket that has none.
        return $this->isEmpty($clean) ? '' : $clean;
    }

    /**
     * Clean a map of values — a translated field, one entry per language.
     *
     * An entry that cleans down to nothing is dropped rather than stored as an
     * empty string: a language with no translation and a language translated
     * to nothing should behave the same way, and the fallback to the default
     * language depends on the key being absent.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function cleanEach(array $values, RichTextProfile $profile = RichTextProfile::Basic): array
    {
        $clean = [];

        foreach ($values as $locale => $value) {
            $cleaned = is_string($value) ? $this->clean($value, $profile) : '';

            if ($cleaned !== '') {
                $clean[$locale] = $cleaned;
            }
        }

        return $clean;
    }

    /**
     * The same content with the markup taken out: what search indexes, what an
     * excerpt is cut from, and what goes in the plain-text part of an e-mail.
     *
     * Block-level tags become newlines first, so `</p><p>` does not weld two
     * sentences into one word and break a search for either.
     */
    public function toText(string $html): string
    {
        // A paragraph break is a blank line and a line break is a newline,
        // because the difference is what makes the plain-text part of an
        // e-mail readable. Cells become spaces rather than newlines so a table
        // row stays a row.
        $spaced = preg_replace('#</(td|th)>#i', ' ', $html) ?? $html;
        $spaced = preg_replace('#</(li|tr|dd|dt|figcaption|caption)>#i', "\n", $spaced) ?? $spaced;
        $spaced = preg_replace('#</(p|div|blockquote|pre|h[1-6]|ul|ol|dl|table|figure)>#i', "\n\n", $spaced) ?? $spaced;
        $spaced = preg_replace('#<(br|hr)\s*/?>#i', "\n", $spaced) ?? $spaced;

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of blank lines, but keep single ones: the paragraph
        // breaks are what makes the text part of an e-mail readable.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The same again on one line, for a search result, a list column or a
     * webhook payload that has no room for paragraphs.
     */
    public function toLine(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->toText($html)));
    }

    /**
     * The first sentence or two, for a search result or a suggestion.
     */
    public function excerpt(string $html, int $length = 200): string
    {
        $text = $this->toLine($html);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($cut, ' ');

        // Cut on a word boundary when there is one near the end; a truncation
        // mid-word reads like a bug.
        return rtrim($lastSpace !== false && $lastSpace > $length * 0.6
            ? mb_substr($cut, 0, $lastSpace)
            : $cut, " \t\n\r\0\x0B.,;:").'…';
    }

    /**
     * Wrap plain text in the markup it was already implying: blank lines
     * separate paragraphs, single newlines are line breaks.
     *
     * This is what converts eleven months of tickets typed into a textarea, and
     * every plain-text mail and API call that arrives from here on.
     */
    public function fromPlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];

        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $html .= '<p>'.str_replace("\n", '<br>', $escaped).'</p>';
        }

        return $html;
    }

    /**
     * True when there is nothing a reader would see.
     *
     * `<p></p>` is what an editor that has been focused and then emptied
     * leaves behind, and `<p><br></p>` is what one that has been focused and
     * never typed into leaves behind. Neither is content, and treating them as
     * content is how a "reply" with no words in it gets sent to a customer.
     */
    public function isEmpty(string $html): bool
    {
        if (trim($html) === '') {
            return true;
        }

        // An image is content even though it contributes no text.
        if (preg_match('/<img\b/i', $html) === 1) {
            return false;
        }

        $text = str_replace("\u{00A0}", ' ', $this->toLine($html));

        return trim($text) === '';
    }

    /**
     * Turn headings into a bold lead line.
     *
     * The message profile has no headings, and simply unwrapping one would
     * leave its words loose at block level — well-formed enough, but it reads
     * as a mistake and the next edit in the editor would have to guess what to
     * do with it. Somebody who pastes a heading into a reply meant "this line
     * matters", and a bold paragraph says that without letting a comment
     * shout in 30px.
     *
     * Runs before the sanitiser, so anything odd it produces is still cleaned.
     */
    private function demoteHeadings(string $html): string
    {
        $html = preg_replace('#<h[1-6]\b[^>]*>#i', '<p><strong>', $html) ?? $html;

        return preg_replace('#</h[1-6]>#i', '</strong></p>', $html) ?? $html;
    }

    /**
     * Whether a value is already markup.
     *
     * Deliberately crude, and deliberately biased towards "yes": a false
     * positive costs a sanitiser pass that leaves stray angle brackets escaped
     * anyway, while a false negative would double-escape real HTML and show
     * somebody `<p>` in their own ticket. `<3` and `a < b` have no closing
     * bracket in the right place and fall through to the plain-text path.
     */
    private function looksLikeHtml(string $value): bool
    {
        return preg_match('/<(\/?[a-z][a-z0-9]*)\b[^>]*>/i', $value) === 1;
    }

    private function sanitizer(RichTextProfile $profile): HtmlSanitizer
    {
        return $this->sanitizers[$profile->value] ??= new HtmlSanitizer($this->config($profile));
    }

    private function config(RichTextProfile $profile): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig;

        foreach ($this->allowed($profile) as $element) {
            $config = $config->allowElement($element, $this->attributesFor($element));
        }

        // Everything an article may have and a message may not is *unwrapped*
        // here, not dropped. The difference between the two profiles is about
        // what a field offers, not about what is dangerous — so a reply pasted
        // out of a Word document loses its heading and keeps its sentence.
        // Only the REMOVED list below takes content with it.
        $unwrapped = [...self::UNWRAPPED, ...array_diff(self::ARTICLE, $this->allowed($profile))];

        foreach ($unwrapped as $element) {
            // `blockElement` keeps the text and throws away the tag.
            $config = $config->blockElement($element);
        }

        foreach (self::REMOVED as $element) {
            // `dropElement` takes the content with it.
            $config = $config->dropElement($element);
        }

        return $config
            // Only schemes a browser will not execute. Refusing `javascript:`
            // is the point; refusing `data:` keeps a base64 payload from being
            // smuggled into an href.
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            // Relative links stay relative, so an article can point at another
            // article or at the portal.
            ->allowRelativeLinks()
            ->allowRelativeMedias();
    }

    /** @return array<int, string> */
    private function allowed(RichTextProfile $profile): array
    {
        return match ($profile) {
            RichTextProfile::Article => self::ARTICLE,
            RichTextProfile::Basic => self::BASIC,
        };
    }

    /**
     * Attributes each element may keep. `class` is allowed nowhere: written
     * content has no business reaching into the application's stylesheet, and a
     * class is the easiest way to make something look like part of the UI.
     *
     * @return array<int, string>
     */
    private function attributesFor(string $element): array
    {
        return match ($element) {
            'a' => ['href', 'title', 'rel', 'target'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'td', 'th' => ['colspan', 'rowspan', 'scope'],
            'col', 'colgroup' => ['span'],
            'ol' => ['start', 'type'],
            'code', 'pre' => ['data-language'],
            default => ['title'],
        };
    }
}
