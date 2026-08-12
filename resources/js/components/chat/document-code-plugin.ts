import { YooptaPlugin } from '@yoopta/editor';
import type { SlateEditor, SlateElement, YooEditor } from '@yoopta/editor';
import type { KeyboardEvent } from 'react';

import { DocumentCode } from '@/components/chat/document-code';

/**
 * A code block, built here rather than taken from @yoopta/code.
 *
 * The package works, and it costs 2,348 kB gzipped against 124 kB for the whole
 * rest of the editor — nineteen times the weight of everything else for one
 * block type, because it bundles the whole of Prettier and a second copy of
 * Shiki. This project already loads Shiki 4 for fenced code in messages. A
 * document should have code blocks; it should not have two highlighters and a
 * code formatter to get them.
 *
 * What is left to build is therefore small: an element, some key handling, and
 * a serialiser. The colours come from the decorator in document-code.tsx.
 */
export function documentCodePlugin() {
    return new YooptaPlugin({
        type: 'Code',
        elements: {
            code: {
                render: DocumentCode,
                props: { nodeType: 'block', language: null },
            },
        },
        options: {
            display: {
                title: 'Code',
                description: 'Code, met kleuren',
            },
            shortcuts: ['```'],
        },
        events: {
            onKeyDown:
                (
                    editor: YooEditor,
                    slate: SlateEditor,
                    options: { currentBlock: { meta: { order: number } } },
                ) =>
                (event: KeyboardEvent) =>
                    handleKeyDown(editor, slate, options.currentBlock, event),
        },
        parsers: {
            html: {
                /*
                 * Without this a code block is worth nothing to body_text and
                 * cannot be searched for — the core hands back an empty string
                 * for any block whose plugin has no html serialiser. See the
                 * same problem, and the same fix, for tables.
                 */
                serialize: (element: SlateElement) =>
                    `<pre><code>${escapeHtml(textOf(element))}</code></pre>`,
            },
            markdown: {
                serialize: (element: SlateElement) => {
                    const language =
                        typeof element.props?.language === 'string'
                            ? element.props.language
                            : '';

                    return `\`\`\`${language}\n${textOf(element)}\n\`\`\`\n`;
                },
            },
        },
    } as unknown as ConstructorParameters<typeof YooptaPlugin>[0]);
}

/**
 * The keys that mean something else inside code.
 *
 * Only three, and each of them is a thing that is wrong by default rather than
 * merely nicer this way.
 */
function handleKeyDown(
    editor: YooEditor,
    slate: SlateEditor,
    currentBlock: { meta: { order: number } },
    event: KeyboardEvent,
): void {
    if (event.key === 'Tab') {
        event.preventDefault();
        // Two spaces rather than a tab character: a tab in a code block is a
        // width nobody agrees on, and it is what Tab does everywhere else in
        // this project's own source.
        slate.insertText('  ');

        return;
    }

    /*
     * Down at the very end of the last code block in the document.
     *
     * Without this a code block at the bottom of a page is a dead end: Enter
     * makes newlines, Down has nowhere to go, and the only way on is to know
     * that Enter twice does something special or that clicking in the strip of
     * padding below adds a paragraph. Neither is discoverable. Down is what
     * everybody tries first, so Down is what makes the next line.
     */
    if (
        event.key === 'ArrowDown' &&
        isLastBlock(editor, currentBlock) &&
        atEnd(slate)
    ) {
        event.preventDefault();

        editor.insertBlock('Paragraph', {
            at: currentBlock.meta.order + 1,
            focus: true,
        });

        return;
    }

    if (event.key !== 'Enter' || event.shiftKey) {
        return;
    }

    event.preventDefault();

    /*
     * Enter is a newline here, not a new block. Which leaves the question of
     * how anybody ever gets out again — hence the escape below: Enter on an
     * already-empty last line ends the block and starts a paragraph after it.
     * Without that, a code block at the end of a document is a room with no
     * door.
     */
    if (endsOnBlankLastLine(slate)) {
        // Take the newline that made the blank line with us, so the block does
        // not keep an empty line at the bottom.
        slate.deleteBackward('character');

        editor.insertBlock('Paragraph', {
            at: currentBlock.meta.order + 1,
            focus: true,
        });

        return;
    }

    slate.insertText('\n');
}

/**
 * Whether the caret sits at the end of the block on a line with nothing on it.
 *
 * Read straight off the one text node a code block has rather than through
 * Slate's Editor helpers, which would mean importing `slate` — a package this
 * application does not depend on directly and should not start depending on for
 * one predicate. Anything more complicated than that single node returns false
 * and Enter simply inserts a newline, which is the safe way to be wrong.
 */
function endsOnBlankLastLine(slate: SlateEditor): boolean {
    const text = soleText(slate);

    return atEnd(slate) && text !== null && text.endsWith('\n');
}

/** Whether the caret sits after the last character of the block. */
function atEnd(slate: SlateEditor): boolean {
    const selection = slate.selection;
    const text = soleText(slate);

    if (selection === null || !isCollapsed(selection) || text === null) {
        return false;
    }

    return selection.anchor.offset === text.length;
}

/**
 * The block's text, when it is the single text node an ordinary code block has.
 *
 * Null for anything more complicated, and every caller treats that as "do the
 * default thing" — which is the safe way to be wrong about a caret.
 */
function soleText(slate: SlateEditor): string | null {
    const element = slate.children[0] as SlateElement | undefined;
    const node = element?.children?.[0] as { text?: string } | undefined;

    return typeof node?.text === 'string' ? node.text : null;
}

function isLastBlock(
    editor: YooEditor,
    currentBlock: { meta: { order: number } },
): boolean {
    return Object.keys(editor.children).length - 1 === currentBlock.meta.order;
}

function isCollapsed(selection: {
    anchor: { offset: number; path: number[] };
    focus: { offset: number; path: number[] };
}): boolean {
    return (
        selection.anchor.offset === selection.focus.offset &&
        selection.anchor.path.join() === selection.focus.path.join()
    );
}

/** Everything the block says, however the text nodes below it are arranged. */
function textOf(node: { children?: unknown[]; text?: string }): string {
    if (typeof node.text === 'string') {
        return node.text;
    }

    return (node.children ?? [])
        .map((child) =>
            textOf(child as { children?: unknown[]; text?: string }),
        )
        .join('');
}

/**
 * Escaped, for the reason the table serialiser is: getPlainText() puts this
 * string through an innerHTML, and code is exactly the kind of text that
 * contains angle brackets.
 */
function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
