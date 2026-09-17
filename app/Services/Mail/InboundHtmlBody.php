<?php

declare(strict_types=1);

namespace App\Services\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Takes the reply out of an HTML mail and leaves the thread behind.
 *
 * The hard part of inbound mail is not the markup, it is knowing where the
 * person stopped writing. Every client marks the boundary differently and none
 * of them agree: Gmail wraps the history in `div.gmail_quote`, Outlook puts a
 * `divRplyFwdMsg` header before it, Apple Mail uses `blockquote type="cite"`,
 * Thunderbird writes a `moz-cite-prefix` line, and plenty of clients mark it
 * with nothing at all beyond "On … wrote:".
 *
 * So the rule is: find the earliest point in the document that is
 * unambiguously the start of the quoted thread, and drop it and everything
 * after it. Nothing else is touched.
 *
 * **Conservative on purpose.** A cut that takes too much loses what the
 * customer wrote, which is far worse than a reply carrying one quoted
 * paragraph too many. Every rule below is one a client actually emits, and a
 * cut that would leave nothing behind is refused outright — a mail whose whole
 * body looks like a quote is a mail we keep in full and let a person read.
 */
class InboundHtmlBody
{
    /**
     * Containers a client puts the quoted thread in. Class names are matched
     * whole, so `gmail_quote_container` does not match `gmail_quote`.
     *
     * @var array<int, string>
     */
    private const QUOTE_CLASSES = [
        'gmail_quote',
        'gmail_quote_container',
        'moz-cite-prefix',
        'yahoo_quoted',
        'OutlookMessageHeader',
        'protonmail_quote',
        'zmail_extra',
    ];

    /**
     * Ids Outlook gives the block it puts above the quoted message. These are
     * the header, not the quote — cutting at them takes the "From: … Sent: …"
     * table with it, which is the point.
     *
     * @var array<int, string>
     */
    private const QUOTE_IDS = [
        'divRplyFwdMsg',
        'appendonsend',
        'mail-editor-reference-message-container',
        'reply-intro',
    ];

    /**
     * The attribution line, for clients that mark the boundary with nothing
     * else. Anchored at the start of the element's own text: a sentence that
     * merely contains the word "From" is somebody writing, not a header.
     *
     * @var array<int, string>
     */
    private const ATTRIBUTION = [
        '/^-{2,}\s*Original Message\s*-{2,}/i',
        '/^-{2,}\s*Oorspronkelijk bericht\s*-{2,}/i',
        '/^On\b.{5,120}\bwrote\s*:/is',
        '/^Op\b.{5,120}\bschreef\b/is',
        '/^Am\b.{5,120}\bschrieb\b/is',
        '/^(From|Van|De|Von)\s*:\s*\S/i',
        '/^(Sent|Verzonden|Gesendet)\s*:\s*\S/i',
    ];

    /**
     * The reply, as HTML, or an empty string when there is nothing to keep.
     */
    public function extract(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = $this->parse($html);
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $cut = $this->findQuoteStart($xpath, $body);

        if ($cut !== null && $this->somethingSurvives($xpath, $cut)) {
            $this->removeFrom($cut, $body);
        }

        return trim($this->innerHtml($body));
    }

    /**
     * Load the markup without letting libxml's opinions about it reach a log.
     *
     * Mail is the worst HTML there is — unclosed tags, Word's namespaces,
     * eight nested tables — and every one of those is a warning we do not need
     * and cannot act on.
     */
    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // The meta forces UTF-8: without it libxml reads the bytes as Latin-1
        // and a Dutch reply comes out as mojibake.
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * The first node, in document order, that starts the quoted thread.
     */
    private function findQuoteStart(DOMXPath $xpath, DOMElement $body): ?DOMNode
    {
        /** @var iterable<DOMElement> $elements */
        $elements = $xpath->query('.//*', $body) ?: [];

        foreach ($elements as $element) {
            if ($this->isQuoteContainer($element) || $this->isAttribution($element)) {
                return $element;
            }
        }

        return null;
    }

    private function isQuoteContainer(DOMElement $element): bool
    {
        if (in_array($element->getAttribute('id'), self::QUOTE_IDS, true)) {
            return true;
        }

        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];

        if (array_intersect($classes, self::QUOTE_CLASSES) !== []) {
            return true;
        }

        // Apple Mail, and anything else following the convention.
        return mb_strtolower($element->nodeName) === 'blockquote'
            && mb_strtolower($element->getAttribute('type')) === 'cite';
    }

    /**
     * An "On … wrote:" line, and nothing longer.
     *
     * The length cap is what keeps this from matching a whole document: an
     * attribution is one line, and an element carrying three paragraphs that
     * happen to begin with "From:" is somebody quoting a header inside their
     * own sentence.
     */
    private function isAttribution(DOMElement $element): bool
    {
        if (! in_array(mb_strtolower($element->nodeName), ['p', 'div', 'span', 'font'], true)) {
            return false;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $element->textContent) ?? '');

        if ($text === '' || mb_strlen($text) > 200) {
            return false;
        }

        foreach (self::ATTRIBUTION as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything the person wrote comes before the cut.
     *
     * A mail whose entire body is a quote — a bare forward, a client that
     * wraps everything — is kept whole. Half a message is worse than a message
     * with a quote under it, because only one of those is obviously wrong to
     * whoever reads it.
     *
     * `preceding::text()` is the whole question in one expression: every text
     * node that ends before this one begins, ancestors excluded. Walking the
     * tree by hand to answer it is how the first version of this method got it
     * backwards and cut the one case it exists to protect.
     */
    private function somethingSurvives(DOMXPath $xpath, DOMNode $cut): bool
    {
        $before = '';

        /** @var iterable<DOMNode> $nodes */
        $nodes = $xpath->query('preceding::text()', $cut) ?: [];

        foreach ($nodes as $node) {
            $before .= $node->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $before) ?? '') !== '';
    }

    /**
     * Remove the cut node and everything that follows it, at every level up to
     * the body.
     */
    private function removeFrom(DOMNode $cut, DOMElement $body): void
    {
        for ($node = $cut; $node !== null && $node !== $body; $node = $parent) {
            $parent = $node->parentNode;

            while ($node->nextSibling !== null) {
                $node->nextSibling->parentNode?->removeChild($node->nextSibling);
            }

            $parent?->removeChild($node);
        }
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
