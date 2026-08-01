import { describe, expect, it } from 'vitest';

import { hoverShortcutKey } from './use-hover-shortcuts';

function press(
    key: string,
    modifiers: Partial<{
        metaKey: boolean;
        ctrlKey: boolean;
        altKey: boolean;
        shiftKey: boolean;
    }> = {},
) {
    return {
        key,
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        shiftKey: false,
        ...modifiers,
    };
}

describe('hoverShortcutKey', () => {
    it.each(['r', 't', 'e', 'd'])('reads %s as a shortcut', (key) => {
        expect(hoverShortcutKey(press(key))).toBe(key);
    });

    // Caps lock is not a modifier, so the letter still arrives uppercase.
    it('reads an uppercase letter as its lowercase shortcut', () => {
        expect(hoverShortcutKey(press('R'))).toBe('r');
    });

    it.each([
        ['metaKey', 'r'],
        ['ctrlKey', 'r'],
        ['altKey', 'r'],
        ['shiftKey', 'R'],
    ])('leaves %s combinations to the browser', (modifier, key) => {
        expect(hoverShortcutKey(press(key, { [modifier]: true }))).toBeNull();
    });

    it.each(['Enter', 'Escape', 'ArrowUp', 'Tab'])(
        'ignores the navigation key %s',
        (key) => {
            expect(hoverShortcutKey(press(key))).toBeNull();
        },
    );
});
