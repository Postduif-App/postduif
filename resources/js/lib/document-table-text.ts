/**
 * A table block, flattened to HTML so that its words can be searched for.
 *
 * Here rather than in document-blocks.ts for two reasons. It is pure — a tree
 * in, a string out, no Yoopta and no Slate — so it can be tested without
 * loading the 500 kB editor chunk. And document-blocks.ts is the module the
 * whole application is arranged to import as late as possible; anything that
 * does not need to be in it should not be.
 *
 * Why it exists at all: @yoopta/table serialises to markdown and to email, but
 * its `html` parser has only a deserialize. getPlainText() builds HTML and
 * reads the innerText off it, and the core hands back an empty string for a
 * block whose plugin cannot serialise to HTML. Without this, a price list in a
 * table is worth nothing to `body_text` and cannot be found by searching for
 * anything in it.
 */

/** The shape this walks: enough of a Slate node to find the words in it. */
export interface TextNode {
    /** 'table', 'table-row', 'table-data-cell'. Carried but not read: which
     *  element is which follows from the nesting, and a table with a row type
     *  this does not recognise still has its words in the same place. */
    type?: string;
    text?: string;
    children?: TextNode[];
    props?: { asHeader?: boolean } & Record<string, unknown>;
}

/**
 * A table as plain HTML: rows, cells, and the words in them.
 *
 * Nothing about the appearance. This is flattened to text a moment later, so a
 * class or a column width here would only be noise in the search index.
 */
export function tableToHtml(element: TextNode): string {
    const rows = childElements(element)
        .map((row) => {
            const cells = childElements(row)
                .map((cell) => {
                    const tag = cell.props?.asHeader === true ? 'th' : 'td';

                    return `<${tag}>${escapeHtml(textOf(cell))}</${tag}>`;
                })
                .join('');

            return `<tr>${cells}</tr>`;
        })
        .join('');

    return `<table><tbody>${rows}</tbody></table>`;
}

/** The element children of a node, skipping the bare text nodes between them. */
function childElements(node: TextNode): TextNode[] {
    return (node.children ?? []).filter(
        (child) => child.children !== undefined,
    );
}

/** Everything a cell says, however deeply it is nested. */
function textOf(node: TextNode): string {
    return (node.children ?? [])
        .map((child) =>
            child.children === undefined ? (child.text ?? '') : textOf(child),
        )
        .join('');
}

/**
 * Escaped, and not as a formality.
 *
 * getPlainText() assigns this HTML to an innerHTML and reads the innerText
 * back. A cell containing `<img src=x onerror=...>` would otherwise be parsed
 * as a tag rather than as the text somebody typed — and an img with a handler
 * on it fires when it is parsed, unlike a script tag, which innerHTML refuses
 * to run. The escaping is what keeps this a serialiser rather than a way to run
 * code by typing into a table.
 */
function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
