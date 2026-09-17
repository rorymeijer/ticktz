import type { Editor } from '@tiptap/react';
import type { ComponentType, SVGProps } from 'react';

import {
    IconBold,
    IconBulletList,
    IconCode,
    IconCodeBlock,
    IconItalic,
    IconLink,
    IconOrderedList,
    IconQuote,
    IconRedo,
    IconRule,
    IconStrike,
    IconUnderline,
    IconUndo,
    IconUnlink,
} from '@/Components/Icons';

export type RichTextProfile = 'basic' | 'article';

/**
 * What the toolbar needs to know about the cursor, sampled once per change.
 *
 * Read through `useEditorState` rather than by calling `editor.isActive()`
 * while rendering: since TipTap 3 the editor does not re-render its React tree
 * on every transaction, so a toolbar built from live calls draws itself once
 * and then shows whatever was true when it mounted — bold never lights up, and
 * undo stays greyed out forever.
 */
export type ToolbarState = {
    bold: boolean;
    italic: boolean;
    underline: boolean;
    strike: boolean;
    code: boolean;
    bulletList: boolean;
    orderedList: boolean;
    blockquote: boolean;
    codeBlock: boolean;
    link: boolean;
    heading: 2 | 3 | 4 | null;
    canUndo: boolean;
    canRedo: boolean;
};

export type ToolbarItem =
    | { kind: 'separator'; id: string }
    | { kind: 'style'; id: string }
    | {
          kind: 'button';
          id: string;
          labelKey: string;
          icon: ComponentType<SVGProps<SVGSVGElement>>;
          /** Pressed state for aria-pressed; omitted for one-shot actions. */
          active?: boolean;
          disabled?: boolean;
          run: () => void;
      };

/**
 * What the toolbar offers, per profile.
 *
 * Built from the same profile name the server sanitises with, so the buttons
 * somebody can press and the markup that survives the save cannot drift apart:
 * adding a heading button to the message profile here would produce a heading
 * that the sanitiser demotes on the way in, which reads as the editor losing
 * the author's work.
 */
export function toolbarItems(
    editor: Editor,
    profile: RichTextProfile,
    state: ToolbarState,
    onLink: () => void,
): ToolbarItem[] {
    const items: ToolbarItem[] = [];

    if (profile === 'article') {
        items.push({ kind: 'style', id: 'style' }, { kind: 'separator', id: 'sep-style' });
    }

    items.push(
        {
            kind: 'button',
            id: 'bold',
            labelKey: 'editor.marks.bold',
            icon: IconBold,
            active: state.bold,
            run: () => editor.chain().focus().toggleBold().run(),
        },
        {
            kind: 'button',
            id: 'italic',
            labelKey: 'editor.marks.italic',
            icon: IconItalic,
            active: state.italic,
            run: () => editor.chain().focus().toggleItalic().run(),
        },
        {
            kind: 'button',
            id: 'underline',
            labelKey: 'editor.marks.underline',
            icon: IconUnderline,
            active: state.underline,
            run: () => editor.chain().focus().toggleUnderline().run(),
        },
        {
            kind: 'button',
            id: 'strike',
            labelKey: 'editor.marks.strike',
            icon: IconStrike,
            active: state.strike,
            run: () => editor.chain().focus().toggleStrike().run(),
        },
        {
            kind: 'button',
            id: 'code',
            labelKey: 'editor.marks.code',
            icon: IconCode,
            active: state.code,
            run: () => editor.chain().focus().toggleCode().run(),
        },
        { kind: 'separator', id: 'sep-marks' },
        {
            kind: 'button',
            id: 'bullet-list',
            labelKey: 'editor.blocks.bullet_list',
            icon: IconBulletList,
            active: state.bulletList,
            run: () => editor.chain().focus().toggleBulletList().run(),
        },
        {
            kind: 'button',
            id: 'ordered-list',
            labelKey: 'editor.blocks.ordered_list',
            icon: IconOrderedList,
            active: state.orderedList,
            run: () => editor.chain().focus().toggleOrderedList().run(),
        },
        {
            kind: 'button',
            id: 'quote',
            labelKey: 'editor.blocks.quote',
            icon: IconQuote,
            active: state.blockquote,
            run: () => editor.chain().focus().toggleBlockquote().run(),
        },
    );

    if (profile === 'article') {
        items.push(
            {
                kind: 'button',
                id: 'code-block',
                labelKey: 'editor.blocks.code_block',
                icon: IconCodeBlock,
                active: state.codeBlock,
                run: () => editor.chain().focus().toggleCodeBlock().run(),
            },
            {
                kind: 'button',
                id: 'rule',
                labelKey: 'editor.blocks.rule',
                icon: IconRule,
                run: () => editor.chain().focus().setHorizontalRule().run(),
            },
        );
    }

    items.push(
        { kind: 'separator', id: 'sep-blocks' },
        {
            kind: 'button',
            id: 'link',
            labelKey: state.link ? 'editor.link.edit' : 'editor.link.add',
            icon: IconLink,
            active: state.link,
            run: onLink,
        },
        {
            kind: 'button',
            id: 'unlink',
            labelKey: 'editor.link.remove',
            icon: IconUnlink,
            disabled: !state.link,
            run: () => editor.chain().focus().extendMarkRange('link').unsetLink().run(),
        },
        { kind: 'separator', id: 'sep-link' },
        {
            kind: 'button',
            id: 'undo',
            labelKey: 'editor.history.undo',
            icon: IconUndo,
            disabled: !state.canUndo,
            run: () => editor.chain().focus().undo().run(),
        },
        {
            kind: 'button',
            id: 'redo',
            labelKey: 'editor.history.redo',
            icon: IconRedo,
            disabled: !state.canRedo,
            run: () => editor.chain().focus().redo().run(),
        },
    );

    return items;
}
