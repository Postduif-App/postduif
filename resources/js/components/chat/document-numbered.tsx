import { useBlockData } from '@yoopta/editor';
import type { PluginElementRenderProps } from '@yoopta/editor';
import { useNumberListCount } from '@yoopta/lists';

import { DocumentListRow } from '@/components/chat/document-list-row';

/**
 * A numbered list item that knows which number it is.
 *
 * Every item in a Yoopta document is its own block, and the numbered-list
 * element renders a complete `<ol>` around a single `<li>`. So four items in a
 * row are four separate lists, each starting over — the whole list reads
 * "1. 1. 1. 1.".
 *
 * The plugin ships useNumberListCount for exactly this: it walks back through
 * the preceding blocks and works out where this one falls in the run. Feeding
 * that to the list's own `start` keeps the element honest for anything reading
 * the document — a copy, a screen reader, a paste into another editor — while
 * the visible marker is drawn by DocumentListRow, because the native one was
 * clipping its digits.
 */
export function DocumentNumberedItem({
    attributes,
    children,
    blockId,
}: PluginElementRenderProps) {
    const block = useBlockData(blockId);
    const count = useNumberListCount(block);

    return (
        <ol {...attributes} data-element-type="numbered-list" start={count}>
            <DocumentListRow marker={`${count}.`}>{children}</DocumentListRow>
        </ol>
    );
}
