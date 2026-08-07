import { useSyncExternalStore } from 'react';

/**
 * Whether the channel list is standing open over a narrow screen.
 *
 * A module-level store rather than a React context, and the reason is where the
 * two halves sit: the button that opens it lives in the header of whatever
 * screen you are on, and the list itself lives in the sidebar beside that
 * header. They are siblings, so no component contains both — a context would
 * have to be mounted in the layout above them purely to introduce them to each
 * other.
 *
 * The same shape use-appearance and composer-draft already use, and it avoids
 * the failure a context has here: the identity of a context object does not
 * survive a hot swap, so an edit while the page is open leaves the sidebar
 * reading a different context than the layout is providing — the provider looks
 * to have vanished, and every consumer throws at once.
 *
 * One value for the whole tab is correct: there is exactly one channel list.
 */
let open = false;

const listeners = new Set<() => void>();

function subscribe(callback: () => void): () => void {
    listeners.add(callback);

    return () => {
        listeners.delete(callback);
    };
}

function getSnapshot(): boolean {
    return open;
}

/** The server draws it closed; so does the first client render, by hydration. */
function getServerSnapshot(): boolean {
    return false;
}

export function setChannelMenuOpen(next: boolean): void {
    if (next === open) {
        return;
    }

    open = next;
    listeners.forEach((listener) => listener());
}

export function useChannelMenuOpen(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
