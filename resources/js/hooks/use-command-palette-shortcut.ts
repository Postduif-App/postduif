import { useEffect } from 'react';

/**
 * ⌘K — or Ctrl+K — opens the palette from anywhere in the chat.
 *
 * Its own hook because the sidebar promises the shortcut on every screen it
 * draws, and for a while only the channel screen honoured it: the listener was
 * written out in one page and never copied to the other three. A promise made
 * by a shared component has to be kept by a shared hook.
 *
 * A toggle rather than an open: pressing it again is how somebody dismisses
 * what they just opened, without reaching for the mouse or hunting for Escape.
 */
export function useCommandPaletteShortcut(
    toggle: (update: (open: boolean) => boolean) => void,
): void {
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                toggle((open) => !open);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [toggle]);
}
