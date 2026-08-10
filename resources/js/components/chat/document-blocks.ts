import Blockquote from '@yoopta/blockquote';
import Callout from '@yoopta/callout';
import Divider from '@yoopta/divider';
import type { CreateYooptaEditorOptions } from '@yoopta/editor';
import { HeadingOne, HeadingThree, HeadingTwo } from '@yoopta/headings';
import Link from '@yoopta/link';
import { BulletedList, NumberedList, TodoList } from '@yoopta/lists';
import { Bold, CodeMark, Italic, Strike, Underline } from '@yoopta/marks';
import Paragraph from '@yoopta/paragraph';

import { DocumentBulletedItem } from '@/components/chat/document-bulleted';
import { DocumentNumberedItem } from '@/components/chat/document-numbered';
import { DocumentTodoItem } from '@/components/chat/document-todo';

/**
 * What a document can contain.
 *
 * This module is the heavy one — importing it pulls in Yoopta and Slate, which
 * together are the reason the editor is loaded on demand rather than with the
 * rest of the chat. Nothing outside the editor chunk may import it; see
 * document-editor.tsx for the boundary that keeps that true.
 *
 * Left out on purpose, and each for a reason rather than for lack of time:
 * images and files, which would need the attachment pipeline and its
 * permission checks rather than a second upload path; tables, which have no
 * sensible answer yet on a phone; and embeds, which fetch from somewhere else
 * and would put a third party inside a private channel.
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
export const DOCUMENT_PLUGINS = [
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
] as unknown as CreateYooptaEditorOptions['plugins'];

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
    { type: 'Divider', icon: 'Minus', key: 'divider' },
] as const;

export type DocumentBlockChoice = (typeof DOCUMENT_BLOCK_MENU)[number];
