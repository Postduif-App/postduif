/** One picture a workspace named for itself. */
export interface CustomEmojiEntry {
    /** What goes between the colons. Lower case, no colons of its own. */
    name: string;
    url: string;
}

/**
 * A shortcode in running text.
 *
 * The same alphabet the server stores and the composer's trigger scans for, so
 * anything that can be typed can also be recognised here. Deliberately not
 * anchored to a word boundary: a shortcode is punctuation-wrapped already, and
 * "(:shipit:)" is how people write it half the time.
 */
const SHORTCODE = /:([a-z0-9][a-z0-9_-]{0,29}):/g;

/** A run of text, or one emoji that was found in it. */
export type EmojiPart =
    | { type: 'text'; value: string }
    | { type: 'emoji'; entry: CustomEmojiEntry };

/**
 * Split text into what should stay text and what should become a picture.
 *
 * Only names this workspace actually has are pulled out. An unknown ":deploy:"
 * stays exactly as somebody typed it — which matters most for the emoji that
 * was deleted last week: the message keeps reading the way it was written
 * rather than sprouting a broken image where a word used to be.
 *
 * Returns a single text part when there is nothing to find, so the caller can
 * hand the result straight on without checking for the common case.
 */
export function splitCustomEmoji(
    value: string,
    byName: Map<string, CustomEmojiEntry>,
): EmojiPart[] {
    if (byName.size === 0 || !value.includes(':')) {
        return [{ type: 'text', value }];
    }

    const parts: EmojiPart[] = [];
    let cursor = 0;

    for (const match of value.matchAll(SHORTCODE)) {
        const entry = byName.get(match[1]);

        if (entry === undefined || match.index === undefined) {
            continue;
        }

        if (match.index > cursor) {
            parts.push({
                type: 'text',
                value: value.slice(cursor, match.index),
            });
        }

        parts.push({ type: 'emoji', entry });

        cursor = match.index + match[0].length;
    }

    if (parts.length === 0) {
        return [{ type: 'text', value }];
    }

    if (cursor < value.length) {
        parts.push({ type: 'text', value: value.slice(cursor) });
    }

    return parts;
}

/**
 * This whole string as one custom emoji, or null.
 *
 * Two callers with the same question. A reaction is stored as the shortcode and
 * nothing else, so a pill asks this to decide between a picture and a piece of
 * text. And ":taart:" sent as an entire message is the message rather than a
 * decoration on one, so it gets drawn large for the same reason a lone 🎉 does.
 */
export function wholeCustomEmoji(
    value: string,
    byName: Map<string, CustomEmojiEntry>,
): CustomEmojiEntry | null {
    const name = /^:([a-z0-9][a-z0-9_-]{0,29}):$/.exec(value.trim());

    return name === null ? null : (byName.get(name[1]) ?? null);
}

/** The list as the lookup everything else here wants. */
export function indexCustomEmoji(
    entries: CustomEmojiEntry[],
): Map<string, CustomEmojiEntry> {
    return new Map(entries.map((entry) => [entry.name, entry]));
}
