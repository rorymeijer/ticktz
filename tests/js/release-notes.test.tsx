import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ReleaseNotes, parseReleaseNotes } from '@/lib/releaseNotes';

describe('release notes', () => {
    it('reads a changelog into headings, paragraphs and lists', () => {
        const blocks = parseReleaseNotes('## Highlights\n\nA sentence.\n\n- One\n- Two\n');

        expect(blocks).toEqual([
            { kind: 'heading', text: 'Highlights' },
            { kind: 'paragraph', text: 'A sentence.' },
            { kind: 'list', items: ['One', 'Two'] },
        ]);
    });

    it('keeps a wrapped bullet with its bullet', () => {
        const blocks = parseReleaseNotes('- A bullet that\n  runs on\n- A second');

        expect(blocks).toEqual([{ kind: 'list', items: ['A bullet that runs on', 'A second'] }]);
    });

    it('renders emphasis as elements', () => {
        render(<ReleaseNotes notes="**Bold** and _italic_ and `code`." />);

        expect(screen.getByText('Bold').tagName).toBe('STRONG');
        expect(screen.getByText('italic').tagName).toBe('EM');
        expect(screen.getByText('code').tagName).toBe('CODE');
    });

    it('never renders markup from a release note', () => {
        const { container } = render(
            <ReleaseNotes notes={'<img src=x onerror=alert(1)>\n\n<script>alert(2)</script>'} />,
        );

        // Nothing was interpreted: it is all text, which is the whole point.
        // These notes come from outside the instance, and the renderer builds
        // React elements rather than an HTML string precisely so that a note
        // has nothing to inject with.
        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('script')).toBeNull();
        expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
        expect(container.textContent).toContain('<script>alert(2)</script>');
    });

    it('leaves an unclosed marker as the character it was typed as', () => {
        const { container } = render(<ReleaseNotes notes="2 * 3 is not emphasis" />);

        expect(container.querySelector('em')).toBeNull();
        expect(container.textContent).toBe('2 * 3 is not emphasis');
    });

    it('treats a numbered list as a list', () => {
        expect(parseReleaseNotes('1. First\n2. Second')).toEqual([
            { kind: 'list', items: ['First', 'Second'] },
        ]);
    });
});
