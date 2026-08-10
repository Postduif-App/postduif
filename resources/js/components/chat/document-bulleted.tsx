import type { PluginElementRenderProps } from '@yoopta/editor';

import { DocumentListRow } from '@/components/chat/document-list-row';

/**
 * A bullet, drawn rather than left to list-style — see DocumentListRow for why,
 * and for what it buys: a bullet, a number and a to-do all start their text in
 * the same column.
 */
export function DocumentBulletedItem({
    attributes,
    children,
}: PluginElementRenderProps) {
    return (
        <ul {...attributes} data-element-type="bulleted-list">
            <DocumentListRow marker="•">{children}</DocumentListRow>
        </ul>
    );
}
