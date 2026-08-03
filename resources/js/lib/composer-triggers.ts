/** Characters that may follow a trigger; covers handles, slugs and emoji names. */
export const FRAGMENT = '[a-z0-9._-]*';

export interface ActiveTrigger {
    char: string;
    query: string;
}

/**
 * The trigger and fragment directly left of the caret, or null.
 *
 * Anchored to a word boundary so an email address does not open the picker
 * halfway through typing it, and neither does "issue#12".
 *
 * Its own module rather than a function inside the composer so the rules can be
 * read back in a test — the slash rule below is subtle enough to be worth
 * pinning down, and the composer itself is a component with a textarea in it.
 */
export function triggerAt(
    value: string,
    caret: number,
    triggers: string,
): ActiveTrigger | null {
    if (triggers === '') {
        return null;
    }

    const before = value.slice(0, caret);

    const match = before.match(
        new RegExp(`(?:^|\\s)([${triggers}])(${FRAGMENT})$`, 'i'),
    );

    if (match === null) {
        return null;
    }

    /*
     * A command only counts at the very start of the message. Anywhere else a
     * slash is a slash — "en/of", a path, a date — and opening a palette
     * halfway through a sentence would be the picker getting in the way rather
     * than helping.
     */
    if (match[1] === '/' && before !== match[0]) {
        return null;
    }

    return { char: match[1], query: match[2].toLowerCase() };
}
