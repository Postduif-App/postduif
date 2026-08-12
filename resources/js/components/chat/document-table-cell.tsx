import type { PluginElementRenderProps } from '@yoopta/editor';

/**
 * One cell of a table, as a header or as an ordinary cell.
 *
 * @yoopta/table has the whole notion of a header row and a header column — it
 * writes `asHeader` onto the cells and toggles it — and then renders every cell
 * as a `<td>` regardless. So the flag was real and invisible: switching a
 * header on changed the document and nothing on the screen.
 *
 * This is the missing half. A `<th>` is not only bold with a grey background —
 * that part `.document-prose th` in app.css does — it is what tells a screen
 * reader which cell names the column it is reading out, which is the entire
 * point of saying a table has a header.
 */
export function DocumentTableCell({
    attributes,
    children,
    element,
}: PluginElementRenderProps) {
    if (element.props?.asHeader === true) {
        return <th {...attributes}>{children}</th>;
    }

    return <td {...attributes}>{children}</td>;
}
