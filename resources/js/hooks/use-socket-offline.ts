import { echo } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

import type { ConnectionStatus } from '@/lib/connection-notice';
import { connectionNotice } from '@/lib/connection-notice';

/**
 * How long the socket may be away before it is worth interrupting somebody.
 *
 * A laptop waking up, a wifi hop, a Reverb deploy: all of these drop the socket
 * for a second or two and then fix themselves. A banner for those is noise, and
 * noise is what makes a banner stop being read.
 */
const GRACE_MS = 4000;

/**
 * Whether the socket has been gone long enough to say so.
 *
 * Subscribes to the connector itself rather than layering timers on top of
 * `useConnectionStatus`. That hook hands the status to the render, which would
 * leave this one deriving state from state — a timer to start, a flag to clear,
 * both of them written from an effect body. Here the outage lives entirely in
 * the subscription: every `setOffline` happens in a callback, either the
 * connector's or the grace timer's, and the component only ever sees the
 * answer.
 *
 * `hasConnected` is a closure variable rather than a ref for the same reason —
 * nothing outside this subscription has any use for it.
 */
export function useSocketOffline(): boolean {
    const [offline, setOffline] = useState(false);

    useEffect(() => {
        let grace: ReturnType<typeof setTimeout> | undefined;
        let hasConnected = false;

        const stopListening = echo().connector.onConnectionChange(
            (status: ConnectionStatus) => {
                if (status === 'connected') {
                    hasConnected = true;
                }

                if (connectionNotice(status, hasConnected) === 'none') {
                    clearTimeout(grace);
                    grace = undefined;
                    setOffline(false);

                    return;
                }

                // Already counting down, or already showing: an outage that
                // rattles through connecting and back does not get to restart
                // its own grace period.
                grace ??= setTimeout(() => setOffline(true), GRACE_MS);
            },
        );

        return () => {
            clearTimeout(grace);
            stopListening();
        };
    }, []);

    return offline;
}
