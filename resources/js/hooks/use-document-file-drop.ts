import type { YooEditor } from '@yoopta/editor';
import { FileCommands } from '@yoopta/file';
import { ImageCommands } from '@yoopta/image';
import { useCallback, useState } from 'react';
import type { ClipboardEvent, DragEvent } from 'react';

import {
    classifyDroppedFiles,
    filesFromTransfer,
} from '@/lib/document-drop-files';
import { uploadDocumentFile } from '@/lib/document-uploads';

/**
 * Paste a screenshot, or drag a file onto the document.
 *
 * Neither works on its own: @yoopta/image 6.0.5 has no drop or paste handling
 * whatsoever, and without this the slash menu is the only way to get a picture
 * into a document — while pasting is how most people put one there in the first
 * place. Dragging is worse than missing: the browser's own default for a file
 * dropped on a page is to open it, which navigates away from the document
 * somebody was writing.
 *
 * The handlers are meant for the capture phase, and that is not a detail. Slate
 * listens for paste on the editable itself, so a bubbling handler would run
 * after the editor had already decided what the paste meant. Capturing on the
 * element around it gets there first — the same reason the slash menu takes its
 * keys in the capture phase.
 */
export function useDocumentFileDrop(
    editor: YooEditor,
    uploadEndpoint: string | null,
) {
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const insert = useCallback(
        async (files: readonly File[]) => {
            const dropped = classifyDroppedFiles(files);

            if (dropped.length === 0 || uploadEndpoint === null) {
                return;
            }

            setError(null);
            setBusy(true);

            /*
             * Where the first block goes, read once and counted up from.
             *
             * insertBlock defaults `at` to the current path, so inserting three
             * pictures without saying where would put each one at the same
             * index — and the second would push the first down, leaving them in
             * the document backwards. A number of its own per file keeps them
             * in the order they were held.
             */
            const base = editor.path.current;

            try {
                for (const [index, entry] of dropped.entries()) {
                    const uploaded = await uploadDocumentFile(
                        uploadEndpoint,
                        entry.file,
                    );

                    const at =
                        typeof base === 'number' ? base + index : undefined;

                    // Focus only on the last one: focusing each in turn would
                    // scroll the page back and forth while the rest upload.
                    const focus = index === dropped.length - 1;

                    if (entry.kind === 'image') {
                        ImageCommands.insertImage(editor, {
                            at,
                            focus,
                            props: {
                                id: String(uploaded.id),
                                src: uploaded.url,
                                alt: uploaded.name,
                                sizes: {
                                    width: uploaded.width ?? 0,
                                    height: uploaded.height ?? 0,
                                },
                            },
                        });

                        continue;
                    }

                    FileCommands.insertFile(editor, {
                        at,
                        focus,
                        props: {
                            id: String(uploaded.id),
                            src: uploaded.url,
                            name: uploaded.name,
                            size: uploaded.size,
                            format: uploaded.name.split('.').pop() ?? '',
                        },
                    });
                }
            } catch (failure) {
                /*
                 * Nearly always a rule the workspace set — too large, or a type
                 * it does not accept — and uploadDocumentFile has already read
                 * the translated sentence off the response. Anything else falls
                 * back to its own message rather than to a shrug.
                 */
                setError(
                    failure instanceof Error
                        ? failure.message
                        : String(failure),
                );
            } finally {
                setBusy(false);
            }
        },
        [editor, uploadEndpoint],
    );

    const onPasteCapture = useCallback(
        (event: ClipboardEvent<HTMLElement>) => {
            const files = filesFromTransfer(event.clipboardData);

            if (files.length === 0) {
                // Ordinary text. Leave it alone: the editor's own paste knows
                // about blocks, marks and its embedded JSON, and this does not.
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            void insert(files);
        },
        [insert],
    );

    const onDropCapture = useCallback(
        (event: DragEvent<HTMLElement>) => {
            const files = filesFromTransfer(event.dataTransfer);

            if (files.length === 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            void insert(files);
        },
        [insert],
    );

    /**
     * Say that a file may be dropped here.
     *
     * preventDefault on dragover is what makes the drop land on us instead of
     * on the browser, which would otherwise open the file over the top of the
     * document. Done even when there is nowhere to upload to — a reader who
     * drags a file onto a document they may not write in should get nothing,
     * not their page replaced by a photo.
     */
    const onDragOverCapture = useCallback((event: DragEvent<HTMLElement>) => {
        if (filesFromTransfer(event.dataTransfer).length === 0) {
            /*
             * Chrome hides the file list during dragover for security and only
             * fills it in on drop; the types array is what is readable here.
             */
            if (!event.dataTransfer?.types.includes('Files')) {
                return;
            }
        }

        event.preventDefault();
    }, []);

    return {
        error,
        busy,
        dismissError: useCallback(() => setError(null), []),
        handlers: { onPasteCapture, onDropCapture, onDragOverCapture },
    };
}
