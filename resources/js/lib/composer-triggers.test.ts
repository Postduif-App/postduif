import { describe, expect, it } from 'vitest';

import { triggerAt } from './composer-triggers';

/** What the chat's message field listens for, plus the command slash. */
const ALL = '@#:/';

describe('triggerAt', () => {
    it('offers nothing to a field that listens for nothing', () => {
        expect(triggerAt('@fen', 4, '')).toBeNull();
    });

    it('picks up a handle being typed', () => {
        expect(triggerAt('hoi @fen', 8, ALL)).toEqual({
            char: '@',
            query: 'fen',
        });
    });

    it('opens on the trigger before anything is typed after it', () => {
        expect(triggerAt('@', 1, ALL)).toEqual({ char: '@', query: '' });
    });

    /** Anchored to a word boundary, or every email address opens the picker. */
    it('leaves an email address alone', () => {
        expect(triggerAt('mail naar anna@klant.nl', 23, ALL)).toBeNull();
    });

    it('leaves an issue number alone', () => {
        expect(triggerAt('zie issue#12', 12, ALL)).toBeNull();
    });

    it('reads only what is left of the caret', () => {
        // Caret sits right after "@fe"; the "nna" beyond it is not being typed.
        expect(triggerAt('@fenna', 3, ALL)).toEqual({
            char: '@',
            query: 'fe',
        });
    });

    it('lowercases the query so matching does not care about capitals', () => {
        expect(triggerAt('@Fenna', 6, ALL)).toEqual({
            char: '@',
            query: 'fenna',
        });
    });

    /*
     * The command rule. A slash anywhere but the very start is an ordinary
     * character, and there are plenty of ordinary uses.
     */
    it('opens the command list at the start of the message', () => {
        expect(triggerAt('/vers', 5, ALL)).toEqual({
            char: '/',
            query: 'vers',
        });
    });

    it('opens it on the bare slash', () => {
        expect(triggerAt('/', 1, ALL)).toEqual({ char: '/', query: '' });
    });

    it.each([
        ['en/of', 5],
        ['dinsdag 3/4', 11],
        ['zie docs/readme', 15],
        ['hoi /versturen', 14],
    ])('treats the slash in %j as an ordinary character', (value, caret) => {
        expect(triggerAt(value, caret, ALL)).toBeNull();
    });

    /** A field with no commands never listens for the slash at all. */
    it('ignores the slash where commands are not offered', () => {
        expect(triggerAt('/versturen', 10, '@#:')).toBeNull();
    });
});
