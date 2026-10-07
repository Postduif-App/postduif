import { useSyncExternalStore } from 'react';

/**
 * Which of your groups a new channel is about to be filed under.
 *
 * A module-level store for the same reason as use-channel-menu: the "+" beside
 * a group heading lives in the sidebar, and the create dialog it opens is
 * mounted by whichever screen you are on — eleven of them. Threading a section
 * id through every page's onCreateChannel would change all of them to carry a
 * value only the sidebar ever sets.
 *
 * The dialog's group picker reads and writes this directly, so a group picked
 * from a heading and a group picked in the dialog are the same choice.
 */
let sectionId: number | null = null;

const listeners = new Set<() => void>();

function subscribe(callback: () => void): () => void {
    listeners.add(callback);

    return () => {
        listeners.delete(callback);
    };
}

function getSnapshot(): number | null {
    return sectionId;
}

/** Nothing is picked until somebody picks it. */
function getServerSnapshot(): number | null {
    return null;
}

export function setCreateChannelSection(next: number | null): void {
    if (next === sectionId) {
        return;
    }

    sectionId = next;
    listeners.forEach((listener) => listener());
}

export function useCreateChannelSection(): number | null {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
