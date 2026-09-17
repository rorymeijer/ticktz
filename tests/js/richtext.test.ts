import { describe, expect, it } from 'vitest';

import { editorValue, isBlankHtml, normaliseUrl } from '@/lib/richtext';
import { imageFilesIn } from '@/lib/richTextUpload';

describe('isBlankHtml', () => {
    /**
     * ProseMirror always keeps one empty block, so a field somebody clicked
     * into and left behind posts markup rather than an empty string. Calling
     * that "filled in" is how a reply with no words in it reaches a customer.
     */
    it.each([
        ['', 'nothing'],
        ['<p></p>', 'an empty paragraph'],
        ['<p><br></p>', 'a paragraph with a break'],
        ['<p>&nbsp;</p>', 'a non-breaking space'],
        ['<p>   </p>', 'spaces'],
        ['<ul><li></li></ul>', 'an empty list item'],
    ])('treats %s as blank (%s)', (html) => {
        expect(isBlankHtml(html)).toBe(true);
    });

    it.each([['<p>Hi</p>'], ['<p><strong>Hi</strong></p>'], ['<ul><li>One</li></ul>']])(
        'does not treat %s as blank',
        (html) => {
            expect(isBlankHtml(html)).toBe(false);
        },
    );

    it('does not treat a lone image as blank', () => {
        expect(isBlankHtml('<p><img src="/a.png" alt=""></p>')).toBe(false);
    });
});

describe('normaliseUrl', () => {
    it('keeps a url that already has a safe scheme', () => {
        expect(normaliseUrl('https://example.test/help')).toBe('https://example.test/help');
        expect(normaliseUrl('http://example.test')).toBe('http://example.test');
        expect(normaliseUrl('mailto:desk@example.test')).toBe('mailto:desk@example.test');
    });

    it('keeps a relative link so an article can point at the portal', () => {
        expect(normaliseUrl('/portal/kb/vpn')).toBe('/portal/kb/vpn');
    });

    it('adds https to a bare host, because nobody types the scheme', () => {
        expect(normaliseUrl('example.test')).toBe('https://example.test');
        expect(normaliseUrl('example.test/help?a=1')).toBe('https://example.test/help?a=1');
    });

    it('reads a bare address as a mail link', () => {
        expect(normaliseUrl('desk@example.test')).toBe('mailto:desk@example.test');
    });

    /**
     * The server sanitiser refuses these too. This is the copy that tells the
     * person, instead of accepting the link and silently dropping it on save.
     */
    it.each([
        ['javascript:alert(1)'],
        ['JaVaScRiPt:alert(1)'],
        ['data:text/html;base64,PHNjcmlwdD4='],
        ['vbscript:msgbox(1)'],
        ['file:///etc/passwd'],
    ])('refuses %s', (input) => {
        expect(normaliseUrl(input)).toBeNull();
    });

    it.each([[''], ['   '], ['just some words'], ['see the manual']])('refuses %s as not an address', (input) => {
        expect(normaliseUrl(input)).toBeNull();
    });

    it('trims what was pasted', () => {
        expect(normaliseUrl('  https://example.test  ')).toBe('https://example.test');
    });
});

describe('editorValue', () => {
    /**
     * The editor's whole contract with a form: an empty document is an empty
     * string, not the paragraph ProseMirror keeps around to put the caret in.
     */
    it('reports an empty document as an empty string', () => {
        expect(editorValue('<p></p>')).toBe('');
        expect(editorValue('<p><br></p>')).toBe('');
    });

    it('passes a document with words in it through unchanged', () => {
        expect(editorValue('<p>Printer is broken</p>')).toBe('<p>Printer is broken</p>');
    });
});

describe('imageFilesIn', () => {
    const png = () => new File(['x'], 'shot.png', { type: 'image/png' });

    it('picks the images out of a drop', () => {
        const list = [png(), new File(['x'], 'notes.txt', { type: 'text/plain' })];

        expect(imageFilesIn(list as unknown as FileList)).toHaveLength(1);
    });

    /**
     * A clipboard carries the same screenshot several ways at once — an image,
     * and the HTML or text a mail client wrapped around it. Only the image is
     * ours to upload.
     */
    it('reads the file entries of a clipboard and ignores the rest', () => {
        const items = [
            { kind: 'string', type: 'text/html', getAsFile: () => null },
            { kind: 'file', type: 'image/png', getAsFile: () => png() },
        ];

        const files = imageFilesIn(items as unknown as DataTransferItemList);

        expect(files).toHaveLength(1);
        expect(files[0].type).toBe('image/png');
    });

    it('says nothing when there is nothing', () => {
        expect(imageFilesIn(null)).toEqual([]);
        expect(imageFilesIn(undefined)).toEqual([]);
    });
});
