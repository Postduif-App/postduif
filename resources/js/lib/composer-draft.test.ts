import { beforeEach, describe, expect, it } from 'vitest';

import {
    clearDraft,
    readDraft,
    readDraftOnServer,
    saveDraft,
    subscribeToDraft,
} from '@/lib/composer-draft';

/**
 * The suite runs in node, which has neither a window nor a localStorage. Both
 * are stubbed here rather than pulled in as a dependency: what is under test is
 * the module's own bookkeeping — the prefix, the isolation between
 * conversations, and throwing an empty draft away — none of which needs a real
 * browser to be wrong.
 */
function stubStorage(): void {
    const store = new Map<string, string>();

    Object.assign(globalThis, {
        window: globalThis,
        localStorage: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
            removeItem: (key: string) => void store.delete(key),
            clear: () => store.clear(),
            get length() {
                return store.size;
            },
        },
    });
}

describe('composer drafts', () => {
    beforeEach(() => stubStorage());

    it('gives back what was typed', () => {
        saveDraft('werkruimte:1', 'half af');

        expect(readDraft('werkruimte:1')).toBe('half af');
    });

    it('keeps conversations apart', () => {
        saveDraft('werkruimte:1', 'in het kanaal');
        saveDraft('werkruimte:1:thread:abc', 'in de thread');

        expect(readDraft('werkruimte:1')).toBe('in het kanaal');
        expect(readDraft('werkruimte:1:thread:abc')).toBe('in de thread');
    });

    it('answers with nothing for a conversation nobody typed in', () => {
        expect(readDraft('werkruimte:9')).toBe('');
    });

    /** An empty draft is removed rather than stored, so nothing accumulates. */
    it('forgets a draft that was emptied', () => {
        saveDraft('werkruimte:1', 'iets');
        saveDraft('werkruimte:1', '   ');

        expect(readDraft('werkruimte:1')).toBe('');
        expect(localStorage.length).toBe(0);
    });

    it('forgets a draft that was sent', () => {
        saveDraft('werkruimte:1', 'verstuurd');
        clearDraft('werkruimte:1');

        expect(readDraft('werkruimte:1')).toBe('');
    });

    /**
     * The server has no storage. Rendering a filled field there and an empty
     * one in the browser is what React refuses to hydrate.
     */
    it('renders empty on the server', () => {
        saveDraft('werkruimte:1', 'iets');

        expect(readDraftOnServer()).toBe('');
    });

    it('tells one conversation about its own changes and no others', () => {
        let mine = 0;
        let theirs = 0;

        const stopMine = subscribeToDraft('werkruimte:1', () => mine++);
        const stopTheirs = subscribeToDraft('werkruimte:2', () => theirs++);

        saveDraft('werkruimte:1', 'hallo');

        expect(mine).toBe(1);
        expect(theirs).toBe(0);

        stopMine();
        stopTheirs();
    });

    /** A composer with nothing to remember still needs somewhere to type. */
    it('keeps an ephemeral draft out of storage', () => {
        saveDraft('ephemeral:abc', 'een opmerking');

        expect(readDraft('ephemeral:abc')).toBe('een opmerking');
        expect(localStorage.length).toBe(0);
    });
});
