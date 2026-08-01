/**
 * What was typed but not sent, kept per conversation.
 *
 * In localStorage rather than on the server: a half-written message is not
 * something anybody else should be able to see, and it should survive a refresh
 * rather than a change of device. Writing to two devices at once is the case
 * this deliberately does not solve — see pcom-gozz for that as a next step.
 */
const PREFIX = 'composer-draft:';

/** Empty drafts are removed rather than stored, so nothing accumulates. */
export function saveDraft(key: string, body: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        if (body.trim() === '') {
            localStorage.removeItem(PREFIX + key);

            return;
        }

        localStorage.setItem(PREFIX + key, body);
    } catch {
        // A full or blocked storage is not worth losing the keystroke over.
    }
}

export function readDraft(key: string): string {
    if (typeof window === 'undefined') {
        return '';
    }

    try {
        return localStorage.getItem(PREFIX + key) ?? '';
    } catch {
        return '';
    }
}

export function clearDraft(key: string): void {
    saveDraft(key, '');
}
