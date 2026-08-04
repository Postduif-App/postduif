/** What was typed, once the filters have been lifted out of it. */
export interface SearchQuery {
    /** The channel named after "in:", without the "#", or null. */
    channel: string | null;
    /** The handle named after "from:", without the "@", or null. */
    from: string | null;
    /** Everything that was not a filter, with the spacing tidied. */
    terms: string;
}

/**
 * The filters this understands, and what each one narrows.
 *
 * A list rather than a regex alternation spelled out twice, so adding one is a
 * line here instead of an edit in three places.
 */
const FILTERS = ['in', 'from'] as const;

type FilterName = (typeof FILTERS)[number];

/**
 * A filter is "name:value" at the start of a word.
 *
 * The value allows the characters a channel name or a handle is made of — see
 * RecordMentions::PATTERN, which admits the same set for "@". Deliberately not
 * "anything up to a space": "in:algemeen." at the end of a sentence should find
 * #algemeen, and a trailing dot is punctuation rather than part of the name.
 *
 * A leading "#" or "@" is swallowed, because people type what they see: the
 * sidebar shows "#algemeen", so "in:#algemeen" is the natural thing to write.
 */
const PATTERN = new RegExp(
    `(?:^|\\s)(${FILTERS.join('|')}):[#@]?([a-z0-9_-]+(?:\\.[a-z0-9_-]+)*)`,
    'gi',
);

/**
 * Pull "in:" and "from:" out of what somebody typed.
 *
 * Everything the filters did not claim stays the search term, wherever it sat —
 * "wachtwoord in:algemeen van fenna" and "in:algemeen wachtwoord van fenna" ask
 * the same thing, and somebody adding a filter to a query they already typed
 * should not have to move it to the front.
 *
 * The last of a repeated filter wins. Two channels cannot both be the one being
 * searched, and taking the last is what "I changed my mind" looks like when you
 * type it.
 *
 * A filter with no value — "in:" while somebody is still typing — is left
 * alone. It matches nothing here, so it stays in the terms and does no harm
 * until the next keystroke makes it a filter.
 */
export function parseSearchQuery(input: string): SearchQuery {
    const found: Partial<Record<FilterName, string>> = {};

    const terms = input
        .replace(PATTERN, (match, name: string, value: string) => {
            found[name.toLowerCase() as FilterName] = value.toLowerCase();

            // The leading space, if there was one, goes back: taking the whole
            // match would glue the words on either side of the filter together.
            return match.startsWith(' ') ? ' ' : '';
        })
        .replace(/\s+/g, ' ')
        .trim();

    return {
        channel: found.in ?? null,
        from: found.from ?? null,
        terms,
    };
}

/**
 * Write a filter back into a query, replacing one of the same name.
 *
 * For the channel header's search button, which opens the dialog with
 * "in:algemeen " already in it, and for a picker that swaps one channel for
 * another without the reader having to delete anything.
 */
export function withFilter(
    input: string,
    name: FilterName,
    value: string | null,
): string {
    const without = input
        .replace(
            new RegExp(`(?:^|\\s)${name}:[#@]?[a-z0-9_.-]*`, 'gi'),
            (match) => (match.startsWith(' ') ? ' ' : ''),
        )
        .replace(/\s+/g, ' ')
        .trim();

    if (value === null) {
        return without;
    }

    // Trailing space on purpose: this is handed to a field somebody is about to
    // keep typing in, and they should not have to press space first.
    return without === '' ? `${name}:${value} ` : `${name}:${value} ${without}`;
}

/** A filter somebody is halfway through typing, at the end of the query. */
export interface TrailingFilter {
    name: FilterName;
    /** What has been typed after the colon so far, possibly empty. */
    value: string;
}

/**
 * The filter being typed right now, or null.
 *
 * Only at the very end, and only while the caret would still be inside it —
 * that is what separates "offering to complete this" from "this is already a
 * filter and the reader has moved on". Anchored to the end for exactly that
 * reason: a completed filter earlier in the sentence must not keep suggesting.
 *
 * An empty value counts. "in:" with nothing after it is the moment somebody
 * most wants to be shown what they can pick.
 */
export function trailingFilter(input: string): TrailingFilter | null {
    const match = input.match(
        new RegExp(`(?:^|\\s)(${FILTERS.join('|')}):[#@]?([a-z0-9_-]*)$`, 'i'),
    );

    if (!match) {
        return null;
    }

    return {
        name: match[1].toLowerCase() as FilterName,
        value: match[2].toLowerCase(),
    };
}
