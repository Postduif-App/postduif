import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

import type { CustomEmojiEntry } from '@/lib/custom-emoji';
import { indexCustomEmoji } from '@/lib/custom-emoji';
import type { ChatWorkspace } from '@/types/chat';

/**
 * This workspace's own emoji, read off the page rather than passed down.
 *
 * The exception to how everything else here gets its data, and deliberately so.
 * The picker is rendered from four places — the message row, the composer, the
 * prikbord and the feed — and none of them is about emoji; threading a list
 * through all four would put a prop on components that have no opinion about
 * it, only to hand it to a grandchild.
 *
 * Safe on a page that has no workspace at all: the settings screens render the
 * same picker-free components, and an empty list is exactly right there.
 */
export function useCustomEmoji(): {
    entries: CustomEmojiEntry[];
    byName: Map<string, CustomEmojiEntry>;
} {
    const workspace = usePage<{ workspace?: ChatWorkspace }>().props.workspace;

    /*
     * The fallback lives inside the memo rather than beside it. An empty array
     * written outside is a new array on every render, which is exactly the
     * dependency that makes a memo pointless — and this one is depended on by
     * every message row on screen.
     */
    const given = workspace?.customEmoji;

    return useMemo(() => {
        const entries = given ?? [];

        return { entries, byName: indexCustomEmoji(entries) };
    }, [given]);
}
