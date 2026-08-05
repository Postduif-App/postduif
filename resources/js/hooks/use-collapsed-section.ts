import { useCallback, useState } from 'react';

const STORAGE_PREFIX = 'postduif.collapsed.';

function read(key: string): boolean {
    // The page is server-rendered too, where there is no localStorage.
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.localStorage.getItem(STORAGE_PREFIX + key) === '1';
    } catch {
        return false;
    }
}

/**
 * Whether a sidebar section is folded shut, remembered per browser.
 *
 * Local rather than stored per account, for the same reason the emoji history
 * is: it is a view preference on this screen, and putting it on the server
 * would cost a round trip every time somebody clicks a chevron — on a sidebar
 * that is rebuilt on every navigation.
 */
export function useCollapsedSection(key: string): [boolean, () => void] {
    const [collapsed, setCollapsed] = useState<boolean>(() => read(key));

    const toggle = useCallback(() => {
        setCollapsed((current) => {
            const next = !current;

            try {
                window.localStorage.setItem(
                    STORAGE_PREFIX + key,
                    next ? '1' : '0',
                );
            } catch {
                // Private mode, or a full quota. The section still folds for
                // this visit.
            }

            return next;
        });
    }, [key]);

    return [collapsed, toggle];
}
