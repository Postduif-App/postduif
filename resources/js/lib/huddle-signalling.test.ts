import { describe, expect, it } from 'vitest';

import type { HuddleSignal } from '@/lib/huddle-signalling';
import { forMe, isPolite } from '@/lib/huddle-signalling';

const signal = (to: number, from: number): HuddleSignal => ({
    to,
    from,
    kind: 'offer',
    payload: {},
});

describe('who a huddle signal is for', () => {
    it('takes what is addressed to me', () => {
        expect(forMe(signal(7, 9), 7)).toBe(true);
    });

    /** A whisper reaches everybody on the channel, not one peer. */
    it('drops what is addressed to somebody else', () => {
        expect(forMe(signal(9, 7), 7)).toBe(false);
    });

    /** Otherwise a browser would answer its own offer. */
    it('drops my own signal echoed back to me', () => {
        expect(forMe(signal(7, 7), 7)).toBe(false);
    });
});

describe('which side gives way', () => {
    it('makes exactly one of a pair polite', () => {
        expect(isPolite(7, 9)).toBe(true);
        expect(isPolite(9, 7)).toBe(false);
    });

    it('gives both browsers the same answer about the same pair', () => {
        const pairs: [number, number][] = [
            [1, 2],
            [42, 7],
            [100, 3],
        ];

        for (const [a, b] of pairs) {
            expect(isPolite(a, b)).toBe(!isPolite(b, a));
        }
    });
});
