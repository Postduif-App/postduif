import { useEffect } from 'react';

import { status } from '@/routes/session';

/** How often an open, visible tab checks that its session is still alive. */
const INTERVAL_MS = 60_000;

/**
 * Send a member to the login screen once their session has ended.
 *
 * The chat screen can sit open for hours without making a single request: new
 * messages arrive over a websocket that was authorised when it was opened and
 * keeps delivering afterwards. So a member whose session expired — or who
 * signed out on another device, or whose session was wiped by a deploy — goes
 * on watching a conversation they are no longer entitled to see.
 *
 * Polling only while the tab is visible has a deliberate consequence: an active
 * tab keeps its own session alive, while a tab left open in the background lets
 * it lapse and is redirected the moment it is looked at again. Watching the app
 * counts as using it; a forgotten tab does not.
 */
export function useSessionGuard(): void {
    useEffect(() => {
        let stopped = false;

        const check = async () => {
            if (stopped || document.visibilityState !== 'visible') {
                return;
            }

            try {
                const response = await fetch(status.url(), {
                    headers: { Accept: 'application/json' },
                });

                if (response.status === 401) {
                    stopped = true;
                    // A full navigation rather than an Inertia visit: it tears
                    // down the websocket with the page, so nothing keeps
                    // arriving on the way out.
                    window.location.href = '/login';
                }
            } catch {
                // Offline or the server is restarting. Neither means the
                // session ended, so leave the member where they are.
            }
        };

        const timer = window.setInterval(check, INTERVAL_MS);
        // Coming back to a tab is exactly when a lapsed session shows up.
        document.addEventListener('visibilitychange', check);

        return () => {
            stopped = true;
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', check);
        };
    }, []);
}
