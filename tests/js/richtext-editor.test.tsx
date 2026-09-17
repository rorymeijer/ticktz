import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import { RichTextEditor } from '@/Components/RichText/RichTextEditor';

/**
 * `useTranslations` reads the dictionary Inertia shares on every page. In a
 * unit test there is no page, so the hook's one dependency is stubbed and the
 * keys come back as themselves — which also means an assertion here fails if
 * somebody renders a raw key.
 */
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            translations: {
                'editor.toolbar': 'Formatting',
                'editor.hint': 'Rich text.',
                'editor.marks.bold': 'Bold',
                'editor.marks.italic': 'Italic',
                'editor.marks.underline': 'Underline',
                'editor.marks.strike': 'Strikethrough',
                'editor.marks.code': 'Inline code',
                'editor.blocks.bullet_list': 'Bulleted list',
                'editor.blocks.ordered_list': 'Numbered list',
                'editor.blocks.quote': 'Quote',
                'editor.blocks.code_block': 'Code block',
                'editor.blocks.rule': 'Divider',
                'editor.link.add': 'Add link',
                'editor.link.edit': 'Edit link',
                'editor.link.remove': 'Remove link',
                'editor.link.url': 'Web address',
                'editor.link.apply': 'Apply',
                'editor.link.cancel': 'Cancel',
                'editor.link.invalid': 'That does not look like a web address.',
                'editor.link.placeholder': 'https://example.com',
                'editor.history.undo': 'Undo',
                'editor.history.redo': 'Redo',
                'editor.style.label': 'Text style',
                'editor.style.paragraph': 'Normal text',
                'editor.style.h2': 'Heading',
                'editor.style.h3': 'Subheading',
                'editor.style.h4': 'Small heading',
            },
            locale: 'en',
            locales: { en: 'English' },
        },
    }),
}));

/*
 * ProseMirror measures the document as it renders. jsdom implements neither
 * call, and without them the editor throws on mount rather than failing an
 * assertion — which would look like a broken test rather than a missing shim.
 */
beforeAll(() => {
    Range.prototype.getClientRects = () => ({ length: 0, item: () => null, [Symbol.iterator]: function* () {} }) as never;
    Range.prototype.getBoundingClientRect = () => new DOMRect();
});

function Harness({ profile = 'basic' as const, initial = '' }) {
    return <RichTextEditor value={initial} onChange={() => {}} profile={profile} label="Description" />;
}

describe('RichTextEditor', () => {
    it('renders one text box with an accessible name', async () => {
        render(<Harness />);

        const box = await screen.findByRole('textbox', { name: 'Description' });

        expect(box).toHaveAttribute('aria-multiline', 'true');
        expect(box).toHaveAttribute('contenteditable', 'true');
    });

    it('puts the formatting controls in a labelled toolbar', async () => {
        render(<Harness />);

        const toolbar = await screen.findByRole('toolbar', { name: 'Formatting' });

        expect(toolbar).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Bold' })).toBeInTheDocument();
    });

    /**
     * A toolbar is one tab stop. Fourteen buttons that each take one turns
     * "tab to the next field" into a journey.
     */
    it('is a single tab stop with arrow keys inside', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await screen.findByRole('toolbar', { name: 'Formatting' });

        const bold = screen.getByRole('button', { name: 'Bold' });
        const italic = screen.getByRole('button', { name: 'Italic' });

        expect(bold).toHaveAttribute('tabindex', '0');
        expect(italic).toHaveAttribute('tabindex', '-1');

        bold.focus();
        await user.keyboard('{ArrowRight}');

        expect(italic).toHaveFocus();
        expect(italic).toHaveAttribute('tabindex', '0');
        expect(bold).toHaveAttribute('tabindex', '-1');
    });

    it('reports the state of a toggle rather than only colouring it', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await screen.findByRole('toolbar', { name: 'Formatting' });

        const bold = screen.getByRole('button', { name: 'Bold' });

        expect(bold).toHaveAttribute('aria-pressed', 'false');

        await user.click(bold);

        await waitFor(() => expect(bold).toHaveAttribute('aria-pressed', 'true'));
    });

    /**
     * The message profile is a message, not a document. The server demotes a
     * heading on the way in, so offering the button would only let somebody
     * watch their formatting disappear on save.
     */
    it('offers headings, code blocks and dividers to an article and not to a message', async () => {
        const { unmount } = render(<Harness />);

        await screen.findByRole('toolbar', { name: 'Formatting' });

        expect(screen.queryByRole('combobox', { name: 'Text style' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Code block' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Divider' })).not.toBeInTheDocument();

        unmount();

        render(<Harness profile="article" />);

        await screen.findByRole('toolbar', { name: 'Formatting' });

        expect(screen.getByRole('combobox', { name: 'Text style' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Code block' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Divider' })).toBeInTheDocument();
    });

    it('offers to remove a link only when the cursor is in one', async () => {
        render(<Harness />);

        await screen.findByRole('toolbar', { name: 'Formatting' });

        expect(screen.getByRole('button', { name: 'Remove link' })).toBeDisabled();
    });

    /**
     * `window.prompt` cannot be styled, translated or told what went wrong,
     * and some browsers refuse to show it at all.
     */
    it('asks for a link in a labelled field and refuses one a browser would execute', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await screen.findByRole('toolbar', { name: 'Formatting' });
        await user.click(screen.getByRole('button', { name: 'Add link' }));

        const url = await screen.findByRole('textbox', { name: 'Web address' });

        await user.type(url, 'javascript:alert(1)');
        await user.click(screen.getByRole('button', { name: 'Apply' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('does not look like a web address');
        expect(url).toHaveAttribute('aria-invalid', 'true');
    });

    it('starts from the value it was given', async () => {
        render(<Harness initial="<p>Printer is broken</p>" />);

        const box = await screen.findByRole('textbox', { name: 'Description' });

        expect(box).toHaveTextContent('Printer is broken');
    });

    /*
     * There is no test here for typing into the field.
     *
     * ProseMirror edits through the browser's own contenteditable machinery —
     * beforeinput events and DOM mutation records — and jsdom implements none
     * of it, so a simulated keystroke changes nothing and the assertion passes
     * or fails for reasons that have nothing to do with this component. What
     * the editor hands back for an emptied field is `editorValue()`, covered
     * in richtext.test.ts; what it does with a real keystroke belongs to the
     * accessibility run, which drives a real browser.
     */
});
