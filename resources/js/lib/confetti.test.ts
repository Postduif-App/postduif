import { describe, expect, it } from 'vitest';

import { isCelebration } from '@/lib/confetti';

describe('celebration messages', () => {
    it('recognises a lone party popper', () => {
        expect(isCelebration('🎉')).toBe(true);
    });

    it('recognises a few of them', () => {
        expect(isCelebration('🎉🎉')).toBe(true);
        expect(isCelebration('🎉🎉🎉')).toBe(true);
    });

    it('ignores space around it', () => {
        expect(isCelebration('  🎉 ')).toBe(true);
    });

    /** Invisible either way, so it changes nothing about what was sent. */
    it('accepts the variation selector', () => {
        expect(isCelebration('🎉\u{FE0F}')).toBe(true);
    });

    /** Inherited from the emoji-only rule: past a handful it is a wall. */
    it('stops where the large-emoji rendering stops', () => {
        expect(isCelebration('🎉🎉🎉🎉')).toBe(false);
    });

    it('leaves a message with words alone', () => {
        expect(isCelebration('gefeliciteerd 🎉')).toBe(false);
        expect(isCelebration('🎉 gefeliciteerd')).toBe(false);
    });

    it('recognises the other two ways of cheering', () => {
        expect(isCelebration('🎊')).toBe(true);
        expect(isCelebration('🥳')).toBe(true);
    });

    it('accepts them mixed', () => {
        expect(isCelebration('🎉🥳')).toBe(true);
        expect(isCelebration('🎊🎉🥳')).toBe(true);
    });

    /** Said far more often in passing than in celebration. */
    it('leaves the everyday emoji alone', () => {
        expect(isCelebration('🔥')).toBe(false);
        expect(isCelebration('✨')).toBe(false);
        expect(isCelebration('👍')).toBe(false);
        expect(isCelebration('🎉👍')).toBe(false);
    });

    it('is false for an empty message', () => {
        expect(isCelebration('')).toBe(false);
        expect(isCelebration('   ')).toBe(false);
    });
});
