import { useCallback, useState } from 'react';

const STORAGE_KEY = 'pcom.recent-emoji';
const LIMIT = 8;

function read(): string[] {
    // The page is server-rendered too, where there is no localStorage.
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored.filter((item): item is string => typeof item === 'string')
            : [];
    } catch {
        // A hand-edited or half-written entry is not worth a broken picker.
        return [];
    }
}

/**
 * The emoji this browser reached for last, most recent first.
 *
 * Deliberately local rather than stored per account: which emoji you use is a
 * habit, not data anyone else needs, and keeping it out of the database means
 * picking one costs no write.
 */
export function useRecentEmoji(): [string[], (emoji: string) => void] {
    const [recent, setRecent] = useState<string[]>(read);

    const remember = useCallback((emoji: string) => {
        setRecent((current) => {
            const next = [
                emoji,
                ...current.filter((item) => item !== emoji),
            ].slice(0, LIMIT);

            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {
                // Private mode, or a full quota. The list still works for now.
            }

            return next;
        });
    }, []);

    return [recent, remember];
}
