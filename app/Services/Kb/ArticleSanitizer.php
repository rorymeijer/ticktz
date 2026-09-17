<?php

declare(strict_types=1);

namespace App\Services\Kb;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Makes an article body safe to store.
 *
 * Article bodies are rich text written by agents and read by requesters, which
 * makes them the one place in Ticktz where somebody's input is rendered as
 * markup in somebody else's browser. Unsanitised, an agent could plant a
 * script that runs with an administrator's session — a privilege escalation
 * dressed up as a help article.
 *
 * Two decisions worth stating:
 *
 * 1. **Sanitised on the way in, never on the way out.** A body that is safe in
 *    the database is safe everywhere it is rendered — the portal, the agent
 *    console, a future export. Sanitising on render means remembering to do it
 *    at every call site, and one forgotten `v-html` is the whole game.
 * 2. **Symfony's sanitiser, not a hand-rolled one.** HTML sanitisation is full
 *    of mutation-XSS corners that look fine until they do not, and Symfony is
 *    already throughout this tree. See the decisions doc.
 *
 * The allowlist is deliberately a help-article vocabulary: headings, text,
 * lists, tables, links, images, code. No forms, no media, no SVG, no iframes,
 * and no style attributes.
 */
class ArticleSanitizer
{
    /**
     * Elements an article may contain. Everything else is removed along with
     * its content, except the formatting tags listed below, which are unwrapped
     * so their text survives.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'p', 'br', 'hr', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'blockquote', 'pre', 'code', 'kbd', 'samp', 'var',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a', 'img', 'figure', 'figcaption',
    ];

    /**
     * Legacy and pasted-from-a-word-processor formatting. These are unwrapped
     * rather than deleted, so somebody who pastes `<font>Read this</font>`
     * loses the font tag and keeps the sentence.
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

    private ?HtmlSanitizer $sanitizer = null;

    /**
     * Clean a body for storage.
     */
    public function clean(string $html): string
    {
        return trim($this->sanitizer()->sanitize($html));
    }

    /**
     * The same content with the markup taken out: what search indexes and what
     * an auto-generated excerpt is cut from.
     *
     * Block-level tags become spaces first, so "</p><p>" does not weld two
     * sentences into one word and break a search for either.
     */
    public function toText(string $html): string
    {
        $spaced = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote|pre|dd|dt|figcaption|td|th)>#i', ' ', $html) ?? $html;
        $spaced = preg_replace('#<br\s*/?>#i', ' ', $spaced) ?? $spaced;

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first sentence or two, for a search result or a suggestion.
     */
    public function excerpt(string $html, int $length = 200): string
    {
        $text = $this->toText($html);

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

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer($this->config());
    }

    private function config(): HtmlSanitizerConfig
    {
        // Deliberately *not* `allowStaticElements()`. That helper adds a broad
        // default set — <marquee> and friends among them — and then this list
        // would be adding to a default-allow config rather than being the
        // allowlist. An empty config that only gains what is named below is
        // the one shape that cannot be wrong about an element nobody thought
        // to consider.
        $config = new HtmlSanitizerConfig;

        foreach (self::ALLOWED as $element) {
            $config = $config->allowElement($element, $this->attributesFor($element));
        }

        foreach (self::UNWRAPPED as $element) {
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

    /**
     * Attributes each element may keep. `class` is allowed nowhere: an article
     * has no business reaching into the application's stylesheet, and a class
     * is the easiest way to make something look like part of the UI.
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
