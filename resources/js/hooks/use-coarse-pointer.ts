import { useSyncExternalStore } from 'react';

/**
 * Whether this device has no pointer that can hover — a phone or a tablet.
 *
 * The question is about the input, not the width: a touchscreen laptop is wide
 * and still cannot hover, and a phone held sideways is not a mouse. Everything
 * that appears on hover has to offer a second way in on such a device, and this
 * is what tells a component which of the two it is drawing.
 *
 * Shaped like use-mobile: a media query read through useSyncExternalStore, so
 * the server render and the first client render agree — false, because the
 * server has no idea what is holding the page.
 */
const mql =
    typeof window === 'undefined'
        ? undefined
        : window.matchMedia('(hover: none)');

function subscribe(callback: () => void): () => void {
    if (!mql) {
        return () => {};
    }

    mql.addEventListener('change', callback);

    return () => mql.removeEventListener('change', callback);
}

function getSnapshot(): boolean {
    return mql?.matches ?? false;
}

function getServerSnapshot(): boolean {
    return false;
}

export function useCoarsePointer(): boolean {
    return useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
}
