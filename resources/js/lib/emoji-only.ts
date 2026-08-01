/**
 * Whether a message is nothing but emoji.
 *
 * Worth knowing because such a message is drawn larger: "🎉" at body size is a
 * speck, and the whole point of sending one on its own is that it is the
 * message rather than a decoration on it.
 *
 * Counted with Intl.Segmenter rather than by character, because an emoji is
 * routinely several code points — a family, a flag, a thumbs-up with a skin
 * tone — and splitting on characters would count one gesture as four.
 */
const MAX_EMOJI = 3;

/**
 * The property that says "this code point is rendered as a picture".
 *
 * Extended_Pictographic rather than Emoji: the latter is true for the digits
 * 0-9, which would make "42" an emoji message.
 */
const PICTOGRAPHIC = /\p{Extended_Pictographic}/u;

/**
 * The invisible parts an emoji is assembled from: the zero-width joiner, the
 * variation selector that says "draw this as a picture", the keycap mark, and
 * the skin tones.
 *
 * Checked by code point rather than with a character class. A class holding
 * combining marks is what eslint's misleading-character-class rule warns
 * about, and it is right to: those marks attach to whatever sits beside them,
 * so the class reads as something other than what it matches.
 */
function isModifier(grapheme: string): boolean {
    return [...grapheme].every((character) => {
        const point = character.codePointAt(0) ?? 0;

        return (
            point === 0x200d || // zero-width joiner
            point === 0xfe0f || // "draw as a picture"
            point === 0x20e3 || // keycap
            (point >= 0x1f3fb && point <= 0x1f3ff) // skin tones
        );
    });
}

export function isEmojiOnly(body: string): boolean {
    const trimmed = body.trim();

    if (trimmed === '') {
        return false;
    }

    // Intl.Segmenter is in every browser this application targets, but not in
    // every runtime that renders it server-side — and a message drawn one size
    // on the server and another in the browser is a hydration mismatch.
    if (typeof Intl.Segmenter === 'undefined') {
        return false;
    }

    const segmenter = new Intl.Segmenter(undefined, {
        granularity: 'grapheme',
    });
    const graphemes = [...segmenter.segment(trimmed)].map(
        (entry) => entry.segment,
    );

    if (graphemes.length === 0 || graphemes.length > MAX_EMOJI) {
        return false;
    }

    return graphemes.every(
        (grapheme) =>
            PICTOGRAPHIC.test(grapheme) ||
            // A grapheme may be a modifier on its own after segmentation.
            isModifier(grapheme),
    );
}
