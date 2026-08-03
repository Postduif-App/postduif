import type { ChannelSummary } from '@/types/chat';

/**
 * How well a channel answers to what somebody typed, or null for no match.
 *
 * Three tiers rather than one clever score, because the tiers are what people
 * actually expect: a name that starts with what you typed comes first, one that
 * merely contains it comes next, and a loose letter-by-letter match comes last.
 * "alg" finds #algemeen through the first rule; "gem" finds it through the
 * second; "agm" through the third.
 *
 * Lower is better, so the caller can sort ascending without inverting anything.
 */
function rank(label: string, query: string): number | null {
    const haystack = label.toLowerCase();

    if (haystack.startsWith(query)) {
        return 0;
    }

    const at = haystack.indexOf(query);

    if (at !== -1) {
        // Where it matched breaks the tie: "#deploys" beats "#oude-deploys"
        // when somebody types "deploy".
        return 1 + at / 100;
    }

    /*
     * The loose pass: every letter in order, gaps allowed. Last resort, and
     * scored well behind the other two — it is the rule that matches almost
     * everything, so it must never outrank a real substring hit.
     */
    let index = 0;

    for (const character of haystack) {
        if (character === query[index]) {
            index++;
        }

        if (index === query.length) {
            return 100;
        }
    }

    return null;
}

/**
 * The channels and conversations worth offering for what has been typed.
 *
 * Filtered in the browser rather than asked of the server, and that is the
 * whole point of it: jumping to a channel is the commonest thing anybody does
 * with a palette, and it has to happen between keystrokes. The list is already
 * on the page for the sidebar, so there is nothing to fetch.
 *
 * An empty query returns nothing here — what an empty palette shows is a
 * different question, answered by its caller.
 */
export function matchChannels(
    channels: ChannelSummary[],
    query: string,
    limit = 6,
): ChannelSummary[] {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return [];
    }

    return channels
        .map((channel) => ({
            channel,
            // The label is what somebody sees in the sidebar, and therefore
            // what they are typing at — for a DM that is a person's name, not
            // a channel name.
            score: rank(channel.label, needle),
        }))
        .filter(
            (entry): entry is { channel: ChannelSummary; score: number } =>
                entry.score !== null,
        )
        .sort((a, b) => {
            if (a.score !== b.score) {
                return a.score - b.score;
            }

            // Same quality of match: the one with unread messages is the one
            // somebody is more likely to be heading for.
            const unread = b.channel.unreadCount - a.channel.unreadCount;

            return unread !== 0
                ? unread
                : a.channel.label.localeCompare(b.channel.label);
        })
        .slice(0, limit)
        .map((entry) => entry.channel);
}
