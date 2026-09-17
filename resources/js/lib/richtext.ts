/**
 * Helpers shared by the editor and its tests.
 */

/**
 * What an editor with nothing in it produces.
 *
 * ProseMirror always keeps one empty block, so a field somebody clicked into
 * and then left posts `<p></p>` rather than an empty string. Sending that to
 * the server would make `required` pass on a blank reply, so the editor emits
 * an empty string instead and this is the shared definition of "blank".
 */
export function isBlankHtml(html: string): boolean {
    if (!html) {
        return true;
    }

    if (/<img\b/i.test(html)) {
        return false;
    }

    const text = html
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;| /gi, ' ')
        .trim();

    return text === '';
}

/**
 * What the editor hands to the form for a given document.
 *
 * The whole of it is "blank means blank": ProseMirror's empty document is
 * `<p></p>`, and posting that would make `required` pass on an empty reply,
 * make "this ticket has a description" true for one that has none, and put an
 * empty paragraph in an e-mail. Its own function because that is the sentence
 * worth testing — the alternative is a conditional buried in an event handler
 * that no test can reach without a real browser.
 */
export function editorValue(html: string): string {
    return isBlankHtml(html) ? '' : html;
}

/**
 * Turn what somebody typed into a link box into a URL, or null if it cannot be
 * one.
 *
 * People paste `example.com/help` far more often than they type a scheme, so a
 * bare host gets `https://`. What is refused is the part that matters:
 * anything whose scheme a browser would execute. The server sanitiser refuses
 * those too — this is the copy that gives the person an error instead of
 * silently dropping their link.
 */
export function normaliseUrl(input: string): string | null {
    const value = input.trim();

    if (value === '') {
        return null;
    }

    // Relative links inside the instance: an article pointing at the portal.
    if (value.startsWith('/')) {
        return value;
    }

    const scheme = value.match(/^([a-z][a-z0-9+.-]*):/i)?.[1]?.toLowerCase();

    if (scheme) {
        return ['http', 'https', 'mailto'].includes(scheme) ? value : null;
    }

    // An address with no scheme is a mail address if it looks like one.
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return `mailto:${value}`;
    }

    // A bare host, with or without a path. Refuse anything with whitespace in
    // it — that is a sentence, not an address.
    if (/^[^\s/]+\.[^\s/]{2,}(\/\S*)?$/.test(value)) {
        return `https://${value}`;
    }

    return null;
}
