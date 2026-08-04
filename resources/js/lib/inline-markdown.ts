export type InlineNode =
    | { type: 'text'; value: string }
    | { type: 'code'; value: string }
    | { type: 'strong' | 'em' | 'strike'; children: InlineNode[] };

/**
 * `code`, matched before anything else gets a look at it.
 *
 * No lookarounds, unlike the emphasis markers below: a backtick is not a
 * character that turns up inside identifiers, so there is no snake_case
 * equivalent to protect. What the class does exclude is a newline, which keeps
 * an unclosed backtick from swallowing the rest of a message, and an empty span,
 * so a lone `` pair stays two backticks.
 */
const CODE_PATTERN = /`([^`\n]+)`/g;

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
 * Parse the inline formatting in a message body: code, bold, italic,
 * strikethrough.
 *
 * Deliberately not a markdown library. A chat message is not a document — links
 * are already live, headings and lists would fight the layout, and an HTML
 * renderer would put an injection surface in the one place users type. This
 * returns a tree of plain data, so what ends up on screen is whatever the
 * component decides to render for each node and never raw markup.
 *
 * Code spans win over emphasis, which is the whole point of them: `2 * 3 * 4`
 * inside backticks is arithmetic that must survive verbatim, and `@fenna` in a
 * code sample is a variable rather than somebody being addressed.
 *
 * They are taken out in front and put back at the end, with a placeholder
 * holding each one's seat in between. Splitting the text at every span instead
 * would have been simpler and subtly wrong: the markers of `**kijk naar
 * `$user`**` would land in two different pieces, and a phrase nobody would call
 * ambiguous would come out with its asterisks showing. The placeholder keeps
 * the sentence in one piece, so the emphasis pass sees the shape the author
 * typed.
 */
export function parseInline(text: string, depth = 0): InlineNode[] {
    const spans: string[] = [];

    const masked = text
        /*
         * A NUL in the input would otherwise be read as one of our own markers.
         * It renders as nothing and cannot be typed on purpose, so dropping it is
         * no loss — and it is the one character that has to be ours alone.
         *
         * That is also exactly what no-control-regex objects to, so the rule is
         * switched off for the two lines that need it: a printable sentinel
         * could be typed by a reader and then mistaken for one of ours.
         */
        // eslint-disable-next-line no-control-regex
        .replace(/\u0000/g, '')
        .replace(CODE_PATTERN, (_, code: string) => {
            spans.push(code);

            return `\u0000${spans.length - 1}\u0000`;
        });

    return restore(parseEmphasis(masked, depth), spans);
}

/**
 * A seat held for a code span: its index, fenced off by NULs.
 *
 * Same reason for the disable as in mask(): the marker has to be a character
 * nobody can type, which is the thing the rule is warning about.
 */
// eslint-disable-next-line no-control-regex
const PLACEHOLDER_PATTERN = /\u0000(\d+)\u0000/g;

/**
 * Put the code spans back where their placeholders ended up.
 *
 * After the emphasis pass rather than during it, because a placeholder can come
 * out anywhere in the tree — inside a bold phrase, inside a bold phrase inside
 * an italic one — and only a walk of the finished tree finds all of them.
 */
function restore(nodes: InlineNode[], spans: string[]): InlineNode[] {
    if (spans.length === 0) {
        return nodes;
    }

    return nodes.flatMap((node): InlineNode[] => {
        if (node.type === 'code') {
            return [node];
        }

        if (node.type !== 'text') {
            return [{ ...node, children: restore(node.children, spans) }];
        }

        const parts: InlineNode[] = [];
        let cursor = 0;

        for (const match of node.value.matchAll(PLACEHOLDER_PATTERN)) {
            if (match.index === undefined) {
                continue;
            }

            if (match.index > cursor) {
                parts.push({
                    type: 'text',
                    value: node.value.slice(cursor, match.index),
                });
            }

            parts.push({ type: 'code', value: spans[Number(match[1])] });

            cursor = match.index + match[0].length;
        }

        if (cursor < node.value.length) {
            parts.push({ type: 'text', value: node.value.slice(cursor) });
        }

        return parts;
    });
}

/**
 * The emphasis pass, over text whose code spans are already standing in as
 * placeholders.
 */
function parseEmphasis(text: string, depth: number): InlineNode[] {
    if (depth >= MAX_DEPTH) {
        return text === '' ? [] : [{ type: 'text', value: text }];
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
            // Itself, not parseInline: the text still carries placeholders, and
            // going back through the front door would strip the NULs holding
            // them and leave a bare index behind where a code span should be.
            children: parseEmphasis(inner, depth + 1),
        });

        cursor = match.index + whole.length;
    }

    if (cursor < text.length) {
        nodes.push({ type: 'text', value: text.slice(cursor) });
    }

    return nodes;
}
