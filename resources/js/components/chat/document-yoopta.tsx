/*
 * The React Compiler is on for this project, and Slate is the one thing in it
 * that the compiler's assumptions do not hold for. A Slate editor is a single
 * mutable object that is deliberately the same reference for its whole life —
 * every transform mutates it in place — so memoisation keyed on "did this
 * value change" reads it as never having changed and stops re-rendering a
 * document that has, in fact, been typed into.
 */
'use no memo';

import YooptaEditor, {
    buildBlockData,
    buildBlockElement,
    createYooptaEditor,
} from '@yoopta/editor';
import type { YooptaContentValue } from '@yoopta/editor';
import { useMemo } from 'react';

import { DocumentBlockHandle } from '@/components/chat/document-block-handle';
import {
    DOCUMENT_MARKS,
    documentPlugins,
} from '@/components/chat/document-blocks';
import {
    codeDecorator,
    codeLeafDecorator,
} from '@/components/chat/document-code';
import { DocumentSlashMenu } from '@/components/chat/document-slash-menu';
import { DocumentToolbar } from '@/components/chat/document-toolbar';
import { useDocumentFileDrop } from '@/hooks/use-document-file-drop';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { DocumentContent } from '@/types/chat';

/**
 * One empty paragraph, in the shape the editor stores blocks in.
 *
 * What a document opens with when it has nothing in it yet.
 */
function startingValue(): YooptaContentValue {
    const block = buildBlockData({
        type: 'Paragraph',
        value: [buildBlockElement({ type: 'paragraph' })],
        meta: { order: 0, depth: 0, align: 'left' },
    });

    return { [block.id]: block } as YooptaContentValue;
}

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
     * Where a dropped image or file is sent.
     *
     * Per document rather than a constant, because the address carries the
     * workspace, the channel and the document number. Null for a reader, who
     * still sees every picture that is already in the document — the blocks are
     * registered either way; only the uploading is refused.
     */
    uploadEndpoint: string | null;
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
    uploadEndpoint,
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
     * somebody's rights change, which is a page load anyway. So is the upload
     * address, which changes only when another document is opened — and that
     * remounts this component anyway, see the `key` in the document view.
     */
    const editor = useMemo(() => {
        const created = createYooptaEditor({
            plugins: documentPlugins(uploadEndpoint),
            marks: DOCUMENT_MARKS,
            /*
             * An empty document gets a paragraph to start in.
             *
             * This used to hand over `undefined` on the belief that the editor
             * would then build its own first block. It does not: v6 renders
             * nothing at all — no block, no caret, no placeholder — so a
             * document that had just been created opened as a blank area with
             * nothing to click on and no way to type. Which is every document,
             * once, at the moment it matters most.
             *
             * It will not take a literal empty object either ("Initial value is
             * not valid. Should be an object with blocks."), so the block is
             * built rather than written out by hand — buildBlockData and
             * buildBlockElement are the editor's own, and stay right when the
             * shape changes under us.
             */
            value:
                Object.keys(value).length > 0
                    ? (value as YooptaContentValue)
                    : startingValue(),
            readOnly,
        });

        /*
         * The code colouring, hung on the editor the moment it exists.
         *
         * Here rather than in an effect, and that is not tidiness: an effect
         * runs after the first paint, and Slate asks for its decorations
         * during that paint. Every code block would come up plain and then
         * flash into colour, on every mount, forever.
         *
         * Maps keyed by name, so registering twice is the same as registering
         * once — which is what makes this safe when the editor is rebuilt.
         */
        created.decorators.set('code', codeDecorator(created));
        created.leafDecorators.set('code', codeLeafDecorator);

        return created;
        // eslint-disable-next-line react-hooks/exhaustive-deps -- see above
    }, [readOnly, uploadEndpoint]);

    const drop = useDocumentFileDrop(editor, uploadEndpoint);

    return (
        /*
         * The wrapper exists for the paste and drop handlers, which have to sit
         * on an element around the editor rather than on it: Slate listens for
         * paste on the editable itself, and only the capture phase on an
         * ancestor runs before it does. See use-document-file-drop.
         */
        <div {...drop.handlers}>
            {drop.error !== null && (
                <p
                    className="mb-2 flex items-start justify-between gap-3 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    role="alert"
                >
                    <span>{drop.error}</span>
                    <button
                        type="button"
                        className="shrink-0 underline underline-offset-2"
                        onClick={drop.dismissError}
                    >
                        {t('documents.editor.dismiss_upload_error')}
                    </button>
                </p>
            )}

            {drop.busy && (
                <p className="mb-2 px-1 text-sm text-muted-foreground">
                    {t('documents.editor.uploading')}
                </p>
            )}

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
                    that was the v4 arrangement. Children render inside the
                    editor's context provider, which is what lets these call
                    useYooptaEditor() and find the editor they belong to.

                    Both replace a package that no longer builds against v6:
                    @yoopta/action-menu-list and @yoopta/toolbar are still on
                    the v4 API and import symbols the core has since dropped.
                */}
                <DocumentSlashMenu />
                <DocumentToolbar />
                <DocumentBlockHandle />
            </YooptaEditor>
        </div>
    );
}
