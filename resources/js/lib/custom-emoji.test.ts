import { describe, expect, it } from 'vitest';

import type { CustomEmojiEntry } from '@/lib/custom-emoji';
import {
    indexCustomEmoji,
    splitCustomEmoji,
    wholeCustomEmoji,
} from '@/lib/custom-emoji';

const SHIPIT: CustomEmojiEntry = { name: 'shipit', url: '/emoji/1' };
const TAART: CustomEmojiEntry = { name: 'taart', url: '/emoji/2' };

const known = indexCustomEmoji([SHIPIT, TAART]);

describe("finding a workspace's own emoji in a message", () => {
    it('pulls one out of the middle of a sentence', () => {
        expect(splitCustomEmoji('klaar :shipit: hoor', known)).toEqual([
            { type: 'text', value: 'klaar ' },
            { type: 'emoji', entry: SHIPIT },
            { type: 'text', value: ' hoor' },
        ]);
    });

    it('finds several, of more than one kind', () => {
        expect(splitCustomEmoji(':shipit::taart:', known)).toEqual([
            { type: 'emoji', entry: SHIPIT },
            { type: 'emoji', entry: TAART },
        ]);
    });

    /*
     * The case this exists for: an emoji somebody deleted last week. The
     * message keeps reading the way it was written rather than sprouting a
     * broken image where a word used to be.
     */
    it('leaves a name this workspace does not have as text', () => {
        expect(splitCustomEmoji('klaar :deploy: hoor', known)).toEqual([
            { type: 'text', value: 'klaar :deploy: hoor' },
        ]);
    });

    it('is not fooled by a lone colon, or by a time', () => {
        expect(splitCustomEmoji('om 9:00 dus', known)).toEqual([
            { type: 'text', value: 'om 9:00 dus' },
        ]);
    });

    it('finds one that punctuation is leaning against', () => {
        expect(splitCustomEmoji('(:taart:)', known)).toEqual([
            { type: 'text', value: '(' },
            { type: 'emoji', entry: TAART },
            { type: 'text', value: ')' },
        ]);
    });

    it('hands back the text untouched when the workspace has none', () => {
        expect(splitCustomEmoji(':shipit:', new Map())).toEqual([
            { type: 'text', value: ':shipit:' },
        ]);
    });
});

describe('a string that is one emoji and nothing else', () => {
    it('recognises a stored reaction', () => {
        expect(wholeCustomEmoji(':shipit:', known)).toBe(SHIPIT);
    });

    it('says no to a unicode emoji, which needs no picture', () => {
        expect(wholeCustomEmoji('👍', known)).toBeNull();
    });

    it('says no to a shortcode with words around it', () => {
        expect(wholeCustomEmoji('ja :shipit:', known)).toBeNull();
    });

    /** A reaction left before the emoji was deleted. The pill falls back to text. */
    it('says no to a name that is gone', () => {
        expect(wholeCustomEmoji(':deploy:', known)).toBeNull();
    });
});
