import { describe, expect, it } from 'vitest';

import { isEmojiOnly } from '@/lib/emoji-only';

describe('emoji-only messages', () => {
    it('recognises a single emoji', () => {
        expect(isEmojiOnly('🎉')).toBe(true);
    });

    it('recognises a handful', () => {
        expect(isEmojiOnly('🎉🎉🎉')).toBe(true);
    });

    /** Past a handful it is a row of pictures, not a gesture. */
    it('stops at four', () => {
        expect(isEmojiOnly('🎉🎉🎉🎉')).toBe(false);
    });

    it('counts one gesture as one, however many code points it takes', () => {
        // A thumbs-up with a skin tone, and a family: several code points each.
        expect(isEmojiOnly('👍🏽')).toBe(true);
        expect(isEmojiOnly('👨‍👩‍👧‍👦')).toBe(true);
    });

    it('leaves a message with words alone', () => {
        expect(isEmojiOnly('gefeliciteerd 🎉')).toBe(false);
        expect(isEmojiOnly('🎉 gefeliciteerd')).toBe(false);
    });

    /** Digits carry the Emoji property, which would make "42" an emoji. */
    it('does not mistake numbers for emoji', () => {
        expect(isEmojiOnly('42')).toBe(false);
        expect(isEmojiOnly('0')).toBe(false);
    });

    it('ignores space around it', () => {
        expect(isEmojiOnly('  🎉  ')).toBe(true);
    });

    it('is false for an empty message', () => {
        expect(isEmojiOnly('')).toBe(false);
        expect(isEmojiOnly('   ')).toBe(false);
    });
});
