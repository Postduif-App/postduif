import { describe, expect, it } from 'vitest';

import { tokenRanges } from '@/lib/code-highlight-cache';
import type { CodeToken } from '@/lib/highlight';

function token(content: string, coloured = true): CodeToken {
    return {
        content,
        style: coloured
            ? { '--shiki-light': '#111', '--shiki-dark': '#eee' }
            : {},
    };
}

describe('tokenRanges', () => {
    it('measures a single line from the start of the block', () => {
        const ranges = tokenRanges([[token('const'), token(' x')]]);

        expect(ranges).toEqual([
            { start: 0, end: 5, style: expect.anything() },
            { start: 5, end: 7, style: expect.anything() },
        ]);
    });

    it('counts the newline between two lines', () => {
        /*
         * The whole reason this is a tested function. Shiki groups tokens per
         * line and the newline belongs to neither of them, so a version that
         * simply concatenates the lines puts every colour after the first line
         * one character too far to the left.
         */
        const ranges = tokenRanges([[token('een')], [token('twee')]]);

        expect(ranges[0]).toMatchObject({ start: 0, end: 3 });
        // 'een' is 0..3, the newline is 3, so the second line starts at 4.
        expect(ranges[1]).toMatchObject({ start: 4, end: 8 });
    });

    it('keeps counting through a blank line', () => {
        const ranges = tokenRanges([[token('een')], [], [token('drie')]]);

        expect(ranges[1]).toMatchObject({ start: 5, end: 9 });
    });

    it('leaves out what carries no colour', () => {
        const ranges = tokenRanges([[token('plain', false), token('rood')]]);

        expect(ranges).toHaveLength(1);
        // Dropped, but still counted: the coloured token has to land after it.
        expect(ranges[0]).toMatchObject({ start: 5, end: 9 });
    });

    it('has nothing to say about nothing', () => {
        expect(tokenRanges([])).toEqual([]);
    });
});
