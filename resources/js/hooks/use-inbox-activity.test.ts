import { describe, expect, it } from 'vitest';

import { inboxRose } from './use-inbox-activity';

/**
 * The rule the hook rings on, tested apart from the hook.
 *
 * There is no renderer in this suite, and the interesting part is not React
 * anyway: it is which of the numbers arriving at that component deserve a
 * sound. See the hook itself for why a page load is not one of them.
 */
describe('inboxRose', () => {
    it('is news when more is waiting than before', () => {
        expect(inboxRose(0, 1)).toBe(true);
        expect(inboxRose(3, 9)).toBe(true);
    });

    it('is silent when the same number comes round again', () => {
        // The server sends the whole count per event, so a thread being bumped
        // twice by the same reply chain lands here as the same number twice.
        expect(inboxRose(4, 4)).toBe(false);
    });

    it('is silent when rows are read somewhere else', () => {
        expect(inboxRose(4, 1)).toBe(false);
        expect(inboxRose(1, 0)).toBe(false);
    });
});
