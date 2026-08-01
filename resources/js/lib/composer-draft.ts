/**
 * What was typed but not sent, kept per conversation.
 *
 * An external store rather than component state, and that is the whole design.
 * The value lives in the browser's localStorage, which the server cannot read —
 * so reading it while rendering produces one tree on the server and another in
 * the browser, and React refuses to hydrate that. useSyncExternalStore is the
 * shape React provides for exactly this: a client snapshot, a server snapshot,
 * and a re-render once hydration is done.
 *
 * The same pattern use-appearance follows, for the same reason.
 *
 * Writing to two devices at once is deliberately not solved here: a draft
 * survives a refresh, not a change of desk.
 */
const PREFIX = 'composer-draft:';

/**
 * Drafts for composers that do not persist — a ticket comment, say.
 *
 * In memory only: they still need a store so the hook has one shape, but there
 * is nothing about them worth keeping past the tab.
 */
const ephemeral = new Map<string, string>();

const listeners = new Map<string, Set<() => void>>();

function isPersistent(key: string): boolean {
    return !key.startsWith('ephemeral:');
}

export function readDraft(key: string): string {
    if (!isPersistent(key)) {
        return ephemeral.get(key) ?? '';
    }

    if (typeof window === 'undefined') {
        return '';
    }

    try {
        return localStorage.getItem(PREFIX + key) ?? '';
    } catch {
        return '';
    }
}

/** The server has no storage, so it renders an empty field — as does hydration. */
export function readDraftOnServer(): string {
    return '';
}

/** Empty drafts are removed rather than stored, so nothing accumulates. */
export function saveDraft(key: string, body: string): void {
    if (!isPersistent(key)) {
        if (body === '') {
            ephemeral.delete(key);
        } else {
            ephemeral.set(key, body);
        }

        notify(key);

        return;
    }

    try {
        if (body.trim() === '') {
            localStorage.removeItem(PREFIX + key);
        } else {
            localStorage.setItem(PREFIX + key, body);
        }
    } catch {
        // A full or blocked storage is not worth losing the keystroke over.
    }

    notify(key);
}

export function clearDraft(key: string): void {
    saveDraft(key, '');
}

/**
 * Listen for changes to one conversation's draft.
 *
 * Per key rather than one global listener set: two composers on screen — the
 * channel and an open thread — must not re-render each other on every
 * keystroke.
 */
export function subscribeToDraft(
    key: string,
    callback: () => void,
): () => void {
    const forKey = listeners.get(key) ?? new Set<() => void>();

    forKey.add(callback);
    listeners.set(key, forKey);

    return () => {
        forKey.delete(callback);

        if (forKey.size === 0) {
            listeners.delete(key);
        }
    };
}

function notify(key: string): void {
    listeners.get(key)?.forEach((listener) => listener());
}
