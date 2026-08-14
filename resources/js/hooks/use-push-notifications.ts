import { useCallback, useEffect, useState } from 'react';

import type { PushFailure, PushStatus } from '@/lib/push';
import {
    PushError,
    getSubscriptionState,
    isPushSupported,
    subscribeToPush,
    syncExistingSubscription,
    unsubscribeFromPush,
} from '@/lib/push';

export interface PushNotifications {
    /** What the browser and this person have settled on. See PushStatus. */
    status: PushStatus;
    /** Still working out the initial state, before the first answer is in. */
    isLoading: boolean;
    /** A subscribe or unsubscribe is in flight; the toggle should not move twice. */
    isBusy: boolean;
    /** Why the last attempt failed, for a screen to explain. Cleared on the next try. */
    failure: PushFailure | null;
    /** Turn this device on. Only ever call this from a click. */
    subscribe: () => Promise<void>;
    /** Turn this device off. */
    unsubscribe: () => Promise<void>;
}

/**
 * Notification state for this device, and the two ways to change it.
 *
 * The permission prompt lives behind `subscribe`, and `subscribe` is meant to
 * be wired to a button and to nothing else. Nothing in this hook asks for
 * anything on mount: the effect below only reads what the browser already
 * decided, which is the difference between a settings screen and the sort of
 * site that ambushes you with a prompt before you have read a word.
 *
 * `status` is the whole answer, including the two cases that are not a toggle:
 * `unsupported` (no worker, no PushManager, or a page not on a secure origin)
 * and `denied`, which JavaScript cannot undo. Both need prose rather than a
 * switch that would do nothing when pressed.
 */
export function usePushNotifications(): PushNotifications {
    const [status, setStatus] = useState<PushStatus>(() =>
        isPushSupported() ? 'default' : 'unsupported',
    );
    const [isLoading, setIsLoading] = useState(true);
    const [isBusy, setIsBusy] = useState(false);
    const [failure, setFailure] = useState<PushFailure | null>(null);

    /*
     * Read the browser's answer once, and while we are here, re-register the
     * endpoint it currently holds.
     *
     * That second part is what catches a rotated endpoint. Browsers replace
     * push endpoints on their own schedule; public/sw.js notices and tries to
     * report it, but from a worker it usually cannot prove who it is to
     * Laravel's CSRF check. From here it can. Silent by design — it never
     * subscribes and never prompts, it only re-posts a subscription that is
     * already there.
     */
    useEffect(() => {
        let current = true;

        void getSubscriptionState()
            .then((state) => {
                if (current) {
                    setStatus(state);
                    setIsLoading(false);
                }

                if (state === 'subscribed') {
                    // Failure here is not worth reporting: the subscription is
                    // live either way, and the next visit tries again.
                    return syncExistingSubscription().catch(() => undefined);
                }
            })
            .catch(() => {
                if (current) {
                    setIsLoading(false);
                }
            });

        return () => {
            current = false;
        };
    }, []);

    const run = useCallback(async (change: () => Promise<PushStatus>) => {
        setIsBusy(true);
        setFailure(null);

        try {
            setStatus(await change());
        } catch (error) {
            setFailure(error instanceof PushError ? error.failure : 'failed');

            // A refusal is also a state change: the browser now says 'denied'
            // for good, and the screen has to stop offering a button.
            setStatus(await getSubscriptionState());
        } finally {
            setIsBusy(false);
        }
    }, []);

    return {
        status,
        isLoading,
        isBusy,
        failure,
        subscribe: useCallback(() => run(subscribeToPush), [run]),
        unsubscribe: useCallback(() => run(unsubscribeFromPush), [run]),
    };
}
