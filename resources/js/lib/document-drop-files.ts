/**
 * Which of the things somebody just dropped or pasted belong in the document,
 * and as what.
 *
 * Pure, and here rather than beside the hook that uses it, so it can be tested
 * without a browser and without pulling in the editor chunk.
 */

export type DroppedKind = 'image' | 'file';

export interface DroppedFile {
    file: File;
    kind: DroppedKind;
}

/**
 * Sort what arrived into picture blocks and file blocks.
 *
 * Everything that is not an image becomes a file rather than being refused. A
 * dropped PDF is a thing somebody meant to put in the document, and a silent
 * nothing is the worst answer available — the workspace's own rules about which
 * types are allowed are applied by the server a moment later, where they are
 * written down once.
 *
 * Order is kept: three screenshots pasted at once end up in the document in the
 * order they were held.
 */
export function classifyDroppedFiles(files: readonly File[]): DroppedFile[] {
    return files.filter(isRealFile).map((file) => ({
        file,
        kind: file.type.startsWith('image/') ? 'image' : 'file',
    }));
}

/**
 * Whether this is a file at all.
 *
 * Dropping a folder hands over an entry with no type and no size, and uploading
 * it would fail on the server with a message about file types that would leave
 * somebody staring at a folder wondering what was wrong with it. A genuinely
 * empty file has a type, so this refuses the folder without refusing that.
 */
function isRealFile(file: File): boolean {
    return !(file.type === '' && file.size === 0);
}

/**
 * The files carried by a paste or a drop, if any.
 *
 * Both events expose them the same way, and both also fire for ordinary text —
 * which is the case that matters most here. An empty list means "leave this
 * alone", and the editor goes on to handle the paste as it always did.
 */
export function filesFromTransfer(
    transfer: DataTransfer | null,
): readonly File[] {
    return transfer === null ? [] : Array.from(transfer.files);
}
