import { describe, expect, it } from 'vitest';

import { EMOJI_GROUPS, QUICK_EMOJI } from '@/lib/emoji';

const entries = EMOJI_GROUPS.flatMap((group) => group.entries);

/**
 * What the composer's ":" completion does with what somebody typed. Kept here
 * rather than in the component: the matching is the part worth pinning down,
 * and it has no need of a rendered textarea to be wrong.
 */
function matching(query: string) {
    return entries.filter((entry) =>
        entry.keywords.some((keyword) => keyword.startsWith(query)),
    );
}

describe('emoji lookup', () => {
    it('finds an emoji by the start of a keyword', () => {
        expect(matching('duim').map((entry) => entry.emoji)).toContain('👍');
    });

    it('does not match halfway into a keyword', () => {
        // "uim" is inside "duim", and matching it would open the list on
        // fragments nobody was typing towards.
        expect(matching('uim')).toHaveLength(0);
    });

    it('gives back nothing for something that is not an emoji name', () => {
        expect(matching('qqqq')).toHaveLength(0);
    });

    it('keeps every emoji findable', () => {
        for (const entry of entries) {
            expect(entry.keywords.length).toBeGreaterThan(0);
        }
    });

    it('offers quick emoji that exist in the full set', () => {
        for (const emoji of QUICK_EMOJI) {
            expect(entries.some((entry) => entry.emoji === emoji)).toBe(true);
        }
    });
});
