export type MessageBlock =
    | { type: 'text'; value: string }
    | { type: 'code'; language: string | null; code: string };

/**
 * A fenced block: ``` on its own line, an optional language, and ``` to close.
 *
 * The parts worth explaining:
 *
 * - `(^|\n)` anchors the opening fence to the start of a line, so a message that
 *   merely mentions ``` mid-sentence does not open a block.
 * - `([\w+#.-]*)` is the language. Deliberately narrow — it becomes a label on
 *   screen, and "c++", "objective-c" and "asp.net" are the shapes that argue for
 *   the punctuation it does allow.
 * - `[^\S\n]*` is "spaces and tabs but not a newline", used either side of the
 *   language and after the closing fence. Trailing whitespace is invisible and
 *   nobody should lose a code block to it.
 * - `([\s\S]*?)` is the content, and this is the one place a dot-matches-all
 *   equivalent is wanted: a code block is the thing that is *supposed* to run
 *   across lines.
 * - `(?=\n|$)` requires the closing fence to end its line, so ```` ```php ````
 *   inside a sentence cannot close a block from the middle of one.
 *
 * A fence that is never closed is not a block. It stays literal text, the same
 * bargain parseInline strikes with an unclosed `*` — while somebody is still
 * typing, half a code block should not already be reformatting itself.
 */
const FENCE_PATTERN =
    /(^|\n)```[^\S\n]*([\w+#.-]*)[^\S\n]*\n([\s\S]*?)\n?```[^\S\n]*(?=\n|$)/g;

/**
 * Split a message body into the code blocks in it and the text around them.
 *
 * A separate pass in front of parseInline rather than part of it, because the
 * two work at different levels: emphasis wraps a phrase inside a line, a fence
 * claims whole lines. Running this first is also what makes a code block mean
 * anything — whatever comes back as `code` never sees the emphasis parser or
 * the mention pass, so `**` stays `**` and an `@handle` in a code sample stops
 * notifying somebody who was never being addressed.
 */
export function splitCodeBlocks(body: string): MessageBlock[] {
    const blocks: MessageBlock[] = [];
    let cursor = 0;

    /**
     * Add a stretch of ordinary text, minus the newline that ended the fence
     * above it.
     *
     * That newline is not part of what the author wrote around the block — it is
     * the line break that closed the fence — and the message body is rendered
     * with whitespace preserved, so leaving it in draws a blank line under every
     * block rather than being invisible the way it would be in HTML. The newline
     * *before* an opening fence needs no such care: the pattern matches it, so it
     * never reaches this at all.
     */
    const pushText = (value: string) => {
        const text =
            blocks[blocks.length - 1]?.type === 'code'
                ? value.replace(/^\n/, '')
                : value;

        if (text !== '') {
            blocks.push({ type: 'text', value: text });
        }
    };

    for (const match of body.matchAll(FENCE_PATTERN)) {
        if (match.index === undefined) {
            continue;
        }

        const [whole, , language, code] = match;

        if (match.index > cursor) {
            pushText(body.slice(cursor, match.index));
        }

        blocks.push({
            type: 'code',
            // Empty means the author opened a plain fence, which is a different
            // thing from a language nobody recognises — null so the renderer has
            // no label to draw rather than an empty one.
            language: language === '' ? null : language.toLowerCase(),
            code,
        });

        cursor = match.index + whole.length;
    }

    if (cursor < body.length) {
        pushText(body.slice(cursor));
    }

    return blocks;
}
