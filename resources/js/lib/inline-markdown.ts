export type InlineNode =
    | { type: 'text'; value: string }
    | { type: 'strong' | 'em' | 'strike'; children: InlineNode[] };

/**
 * The markers, matched as a pair through the backreference.
 *
 * The guards are what keep this usable in a chat rather than merely correct:
 *
 * - `(?<![\w*~_])` and `(?![\w*~_])` keep a marker out of the middle of a word,
 *   so `snake_case_names` and `@jan_de_vries` stay what they are instead of
 *   turning half a handle into italics.
 * - `(?=\S)` and `(?<=\S)` refuse an empty or space-hugging span, so `2 * 3 * 4`
 *   is arithmetic and `**` on its own is just two asterisks.
 * - `.` without the s-flag stops at a newline: emphasis someone forgot to close
 *   then ends with the line rather than swallowing the rest of the message.
 *
 * Two-character markers come first in the alternation so `**bold**` is never
 * read as an italic `*` wrapping `*bold*`.
 */
const INLINE_PATTERN =
    /(?<![\w*~_])(\*\*|__|~~|\*|_)(?=\S)(.+?)(?<=\S)\1(?![\w*~_])/g;

const MARKER_TYPES: Record<string, 'strong' | 'em' | 'strike'> = {
    '**': 'strong',
    __: 'strong',
    '~~': 'strike',
    '*': 'em',
    _: 'em',
};

/**
 * How deep emphasis may nest. Bold containing italic is worth supporting;
 * anything past a few levels is a message nobody wrote on purpose, and the
 * limit is what keeps a pathological string from recursing without end.
 */
const MAX_DEPTH = 4;

/**
 * Parse the inline formatting in a message body: bold, italic, strikethrough.
 *
 * Deliberately not a markdown library. A chat message is not a document — links
 * are already live, headings and lists would fight the layout, and an HTML
 * renderer would put an injection surface in the one place users type. This
 * returns a tree of plain data, so what ends up on screen is whatever the
 * component decides to render for each node and never raw markup.
 */
export function parseInline(text: string, depth = 0): InlineNode[] {
    if (depth >= MAX_DEPTH) {
        return [{ type: 'text', value: text }];
    }

    const nodes: InlineNode[] = [];
    let cursor = 0;

    for (const match of text.matchAll(INLINE_PATTERN)) {
        if (match.index === undefined) {
            continue;
        }

        const [whole, marker, inner] = match;

        if (match.index > cursor) {
            nodes.push({
                type: 'text',
                value: text.slice(cursor, match.index),
            });
        }

        nodes.push({
            type: MARKER_TYPES[marker],
            children: parseInline(inner, depth + 1),
        });

        cursor = match.index + whole.length;
    }

    if (cursor < text.length) {
        nodes.push({ type: 'text', value: text.slice(cursor) });
    }

    return nodes;
}
