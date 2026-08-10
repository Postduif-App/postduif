/*
 * The React Compiler is on for this project, and Slate is the one thing in it
 * that the compiler's assumptions do not hold for. A Slate editor is a single
 * mutable object that is deliberately the same reference for its whole life —
 * every transform mutates it in place — so memoisation keyed on "did this
 * value change" reads it as never having changed and stops re-rendering a
 * document that has, in fact, been typed into.
 */
'use no memo';

import YooptaEditor, { createYooptaEditor } from '@yoopta/editor';
import type { YooptaContentValue } from '@yoopta/editor';
import { useMemo } from 'react';

import {
    DOCUMENT_MARKS,
    DOCUMENT_PLUGINS,
} from '@/components/chat/document-blocks';
import { DocumentSlashMenu } from '@/components/chat/document-slash-menu';
import { DocumentToolbar } from '@/components/chat/document-toolbar';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { DocumentContent } from '@/types/chat';

export interface DocumentEditorProps {
    /**
     * The document to open with. Read once, at mount: from then on the editor
     * owns it, and handing it a new one from outside would fight with whoever
     * is typing. Replacing the document means remounting — see the `key` the
     * document view gives this component.
     */
    value: DocumentContent;
    readOnly: boolean;
    /**
     * Fired on every keystroke, with the document and its flattened text.
     *
     * Both, because the caller needs both and only the editor can produce the
     * second one cheaply. Working the plain text out of the JSON afterwards
     * would mean a second implementation of "what does this block say", in a
     * language that cannot see the plugin list.
     *
     * This is not a save. Debouncing it into one is the caller's business.
     */
    onChange: (value: DocumentContent, text: string) => void;
    className?: string;
}

/**
 * The editor itself.
 *
 * Never imported directly by anything that renders during a normal chat visit —
 * this module pulls in Yoopta and Slate, some 620 kB gzipped. document-editor.tsx
 * is the boundary that fetches it, and the only place that should import it.
 */
export default function DocumentYoopta({
    value,
    readOnly,
    onChange,
    className,
}: DocumentEditorProps) {
    const { t } = useTranslate();

    /*
     * In Yoopta 6 the document, the plugin list and readOnly are all given at
     * construction rather than as props — so the editor is built once and the
     * only way to change any of the three is to build another one.
     *
     * `value` is intentionally not a dependency. It changes on every keystroke,
     * and rebuilding the editor on a keystroke would throw away the selection,
     * the undo history and the caret with it. readOnly is: it flips only when
     * somebody's rights change, which is a page load anyway.
     */
    const editor = useMemo(
        () =>
            createYooptaEditor({
                plugins: DOCUMENT_PLUGINS,
                marks: DOCUMENT_MARKS,
                /*
                 * An empty document is handed over as nothing at all, so the
                 * editor builds its own starting block.
                 *
                 * It will not take an empty one: "Initial value is not valid.
                 * Should be an object with blocks." A document that has just been
                 * started is genuinely empty, so this is the ordinary case
                 * rather than an edge — and building a first block here would
                 * mean writing Yoopta's block shape by hand, in the one file
                 * that has the real thing available.
                 */
                value:
                    Object.keys(value).length > 0
                        ? (value as YooptaContentValue)
                        : undefined,
                readOnly,
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- see above
        [readOnly],
    );

    return (
        <YooptaEditor
            editor={editor}
            autoFocus={false}
            placeholder={t('documents.editor.placeholder')}
            className={cn('document-prose', className)}
            /*
             * Yoopta defaults to a fixed 400px and leaves 100px of air below
             * the last block. The width is simply wrong inside a panel that
             * already has one; the padding stays, because a document whose
             * final line sits against the bottom edge is one you cannot click
             * below to keep typing.
             */
            style={{ width: '100%', paddingBottom: '4rem' }}
            onChange={(next) => onChange(next, editor.getPlainText(next))}
        >
            {/*
                The tools, as children rather than through a `tools` prop —
                that was the v4 arrangement. Children render inside the editor's
                context provider, which is what lets these call
                useYooptaEditor() and find the editor they belong to.

                Both replace a package that no longer builds against v6:
                @yoopta/action-menu-list and @yoopta/toolbar are still on the
                v4 API and import symbols the core has since dropped.
            */}
            <DocumentSlashMenu />
            <DocumentToolbar />
        </YooptaEditor>
    );
}
