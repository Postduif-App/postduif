import Blockquote from '@yoopta/blockquote';
import Callout from '@yoopta/callout';
import Divider from '@yoopta/divider';
import { YooptaPlugin } from '@yoopta/editor';
import type { CreateYooptaEditorOptions, SlateElement } from '@yoopta/editor';
import YooptaFile from '@yoopta/file';
import { HeadingOne, HeadingThree, HeadingTwo } from '@yoopta/headings';
import Image from '@yoopta/image';
import Link from '@yoopta/link';
import { BulletedList, NumberedList, TodoList } from '@yoopta/lists';
import { Bold, CodeMark, Italic, Strike, Underline } from '@yoopta/marks';
import Paragraph from '@yoopta/paragraph';
import Table from '@yoopta/table';

import { DocumentBulletedItem } from '@/components/chat/document-bulleted';
import { documentCodePlugin } from '@/components/chat/document-code-plugin';
import { DocumentNumberedItem } from '@/components/chat/document-numbered';
import { DocumentTableCell } from '@/components/chat/document-table-cell';
import { DocumentTodoItem } from '@/components/chat/document-todo';
import { tableToHtml } from '@/lib/document-table-text';
import { uploadDocumentFile } from '@/lib/document-uploads';

/**
 * What a document can contain.
 *
 * This module is the heavy one — importing it pulls in Yoopta and Slate, which
 * together are the reason the editor is loaded on demand rather than with the
 * rest of the chat. Nothing outside the editor chunk may import it; see
 * document-editor.tsx for the boundary that keeps that true.
 *
 * Left out on purpose: embeds, which fetch from somewhere else and would put a
 * third party inside a private channel.
 *
 * And code blocks, which is the one that hurts. @yoopta/code bundles the whole
 * of Prettier plus its own copy of Shiki, and it measures: with it, this chunk
 * is 2,348 kB gzipped; without it, 124 kB. Nineteen times the weight of the
 * entire editor, for one block type — and for a highlighter this project
 * already has, in lib/highlight.ts, where fenced code in messages goes through
 * Shiki 4. A document should have code blocks; it should not have two Shikis and
 * a code formatter to get them. See the follow-up issue for the small plugin
 * that uses the highlighter already here.
 */
/*
 * Typed as what createYooptaEditor() takes, rather than inferred.
 *
 * Yoopta types a plugin by the element map it contributes — Paragraph is a
 * YooptaPlugin<ParagraphElementMap>, HeadingOne a different one — and the
 * option it goes into wants YooptaPlugin<Record<string, SlateElement>>. That is
 * a widening TypeScript will not do on its own, because a generic in that
 * position is invariant: it could be read from as well as written to, even
 * though the editor only ever reads.
 *
 * So the annotation is the assertion, made once and here, instead of at the
 * call site where it would look like a workaround for something else.
 */
export function documentPlugins(
    uploadEndpoint: string | null,
): CreateYooptaEditorOptions['plugins'] {
    return [
        ...documentBasePlugins,
        ...documentMediaPlugins(uploadEndpoint),
        documentCodePlugin(),
    ] as CreateYooptaEditorOptions['plugins'];
}

/**
 * The image, file and table blocks.
 *
 * Apart from the rest because these three need something the others do not: a
 * place to put bytes. The endpoint is per document — it carries the workspace,
 * the channel and the document number — so the plugin list cannot be a module
 * constant the way it was before images existed.
 *
 * Null when there is nowhere to upload to, which is the read-only case. The
 * blocks stay in the list all the same: a reader still has to see the pictures
 * that are already in the document, and a plugin that is not registered renders
 * as nothing at all.
 */
function documentMediaPlugins(uploadEndpoint: string | null) {
    const upload = async (file: File) => {
        if (uploadEndpoint === null) {
            throw new Error('This document cannot be written to.');
        }

        return uploadDocumentFile(uploadEndpoint, file);
    };

    return [
        Image.extend({
            options: {
                upload: async (file: File) => {
                    const uploaded = await upload(file);

                    return {
                        /*
                         * Our own id, in the field the plugin keeps for the
                         * storage provider's. That is what it is: the row in
                         * document_files this picture came from, and the thing
                         * the server reads back to know the file is still in
                         * use. See ReconcileDocumentFiles, which also recovers
                         * it from the src as a second route — a prop the plugin
                         * decided not to keep would otherwise take the picture
                         * with it.
                         */
                        id: String(uploaded.id),
                        src: uploaded.url,
                        alt: uploaded.name,
                        sizes: {
                            width: uploaded.width ?? 0,
                            height: uploaded.height ?? 0,
                        },
                    };
                },
                /*
                 * A picture never wider than the column it sits in.
                 *
                 * Without this the plugin takes the image's own pixel width,
                 * and a screenshot off a 4K display would push the document
                 * sideways — which for a table is a deliberate choice and for a
                 * picture is just a page that no longer fits. The height is
                 * left to follow the aspect ratio.
                 */
                maxSizes: { maxWidth: '100%' },
            },
        }),
        YooptaFile.extend({
            options: {
                upload: async (file: File) => {
                    const uploaded = await upload(file);

                    return {
                        id: String(uploaded.id),
                        src: uploaded.url,
                        name: uploaded.name,
                        size: uploaded.size,
                        format: uploaded.name.split('.').pop() ?? '',
                    };
                },
            },
        }),
        /*
         * The render is taken as it comes, unlike the lists.
         *
         * A table is allowed to be wider than the page and scrolls sideways
         * rather than squeezing its columns — but that is done in CSS, on
         * Yoopta's own block wrapper, rather than by replacing this render. The
         * stock one carries the colgroup and the column-resize handles, and a
         * replacement would have to reproduce both to buy an overflow rule.
         * See `.document-prose .yoopta-block:has(table)` in app.css.
         *
         * What it does need is the serialiser below.
         */
        searchableTable(),
    ];
}

/**
 * The table plugin, with the parser and the cell it does not ship.
 *
 * @yoopta/table serialises to markdown and to email, but its `html` parser has
 * only a deserialize — and html is the one that matters here. getPlainText()
 * builds the HTML and reads the innerText off it, and the core returns an empty
 * string for a block whose plugin cannot serialise to HTML. So without this
 * every table is worth nothing to `body_text`: a price list somebody put in a
 * document could not be found by searching for anything in it. The flattening
 * itself lives in lib/document-table-text.ts, where it can be tested without
 * loading this chunk.
 *
 * Rebuilt rather than extended, because ExtendPlugin has no `parsers` key — the
 * plugin's own definition is public through getPlugin, so this adds one parser
 * and keeps everything else exactly as the package shipped it.
 */
function searchableTable() {
    const plugin = Table.getPlugin;

    /*
     * Cast for exactly the reason the plugin list below is, and it is the same
     * invariance: Table types its elements as the three it contributes —
     * 'table', 'table-row', 'table-data-cell' — while the constructor wants the
     * open Record<string, SlateElement>. TypeScript will not widen a generic in
     * a position it could also be written through, even though nothing here
     * writes. The assertion belongs at this one seam rather than at the call.
     */
    return new YooptaPlugin({
        ...plugin,
        elements: {
            ...plugin.elements,
            /*
             * The cell, replaced. The package writes `asHeader` onto cells and
             * has commands to toggle it, and then renders every one of them as a
             * <td> — so a header row was a flag with nothing behind it. See
             * document-table-cell.tsx.
             */
            'table-data-cell': {
                ...plugin.elements['table-data-cell'],
                render: DocumentTableCell,
            },
        },
        parsers: {
            ...plugin.parsers,
            html: {
                ...plugin.parsers?.html,
                serialize: (element: SlateElement) => tableToHtml(element),
            },
        },
    } as unknown as ConstructorParameters<typeof YooptaPlugin>[0]);
}

const documentBasePlugins = [
    Paragraph,
    HeadingOne,
    HeadingTwo,
    HeadingThree,
    BulletedList.extend({
        elements: { 'bulleted-list': { render: DocumentBulletedItem } },
    }),
    /*
     * Also extended, and for a related reason: each item is its own <ol>, so
     * without help every one of them starts counting at 1. See
     * document-numbered.tsx.
     */
    NumberedList.extend({
        elements: { 'numbered-list': { render: DocumentNumberedItem } },
    }),
    /*
     * Extended rather than taken as it comes. The stock todo-list renders a
     * bare list item with no checkbox and no sign of whether it is done — see
     * document-todo.tsx, which replaces the render with one that has both.
     */
    TodoList.extend({
        elements: { 'todo-list': { render: DocumentTodoItem } },
    }),
    Blockquote,
    Callout,
    Divider,
    Link,
];

/**
 * The inline formatting the toolbar offers.
 *
 * The same five the message composer marks up with plain-text markers, so
 * something bold in a message and something bold in a document are the same
 * gesture. Highlight is available in the plugin but left out: a colour that
 * carries meaning is a colour that has to survive dark mode, and it does not.
 */
export const DOCUMENT_MARKS = [Bold, Italic, Underline, Strike, CodeMark];

/**
 * The block types the slash menu offers, in the order it lists them.
 *
 * Ordered by how often they get reached for rather than by structure: a document
 * is mostly prose with headings in it, and the things somebody scrolls for
 * belong below the things they do not.
 *
 * The `type` values are Yoopta's own plugin keys, which is what
 * editor.toggleBlock() and editor.insertBlock() take.
 */
export const DOCUMENT_BLOCK_MENU = [
    { type: 'Paragraph', icon: 'Type', key: 'paragraph' },
    { type: 'HeadingOne', icon: 'Heading1', key: 'heading_one' },
    { type: 'HeadingTwo', icon: 'Heading2', key: 'heading_two' },
    { type: 'HeadingThree', icon: 'Heading3', key: 'heading_three' },
    { type: 'BulletedList', icon: 'List', key: 'bulleted_list' },
    { type: 'NumberedList', icon: 'ListOrdered', key: 'numbered_list' },
    { type: 'TodoList', icon: 'ListChecks', key: 'todo_list' },
    { type: 'Blockquote', icon: 'Quote', key: 'blockquote' },
    { type: 'Callout', icon: 'Info', key: 'callout' },
    /*
     * Below the prose blocks, which is where they belong by how often they are
     * reached for — but above the divider, because a picture is a thing people
     * come looking for and a horizontal rule is not.
     */
    { type: 'Image', icon: 'ImageIcon', key: 'image' },
    { type: 'File', icon: 'Paperclip', key: 'file' },
    { type: 'Table', icon: 'TableIcon', key: 'table' },
    { type: 'Code', icon: 'Code', key: 'code' },
    { type: 'Divider', icon: 'Minus', key: 'divider' },
] as const;

export type DocumentBlockChoice = (typeof DOCUMENT_BLOCK_MENU)[number];
