import { useEcho } from '@laravel/echo-react';
import { useState } from 'react';

interface InboxPayload {
    workspaceId: number;
    unread: number;
}

/**
 * Keep the inbox badge honest between page loads.
 *
 * Unlike the channel badges beside it, this does not count events upwards. An
 * inbox row collapses — the twentieth reply in a thread bumps a row that is
 * already there — so a client adding one per event would climb away from the
 * truth and only come back on a page load. The server sends the answer instead,
 * and this hook does nothing but hold the most recent one.
 *
 * The server's number wins whenever it changes: a fresh page is newer than
 * anything heard over the socket before it arrived. That reset happens during
 * render rather than in an effect, which is React's own answer for state that
 * follows a prop — an effect would paint the stale number first and then
 * correct it, which is a visible flicker on every navigation.
 */
export function useInboxActivity(
    currentUserId: number,
    workspaceId: number,
    unreadFromServer: number,
): number {
    const [state, setState] = useState({
        fromServer: unreadFromServer,
        unread: unreadFromServer,
    });

    if (state.fromServer !== unreadFromServer) {
        setState({ fromServer: unreadFromServer, unread: unreadFromServer });
    }

    useEcho<InboxPayload>(
        `App.Models.User.${currentUserId}`,
        '.inbox.updated',
        (payload) => {
            // One socket carries every workspace this member belongs to, and
            // the badge on screen speaks for one of them.
            if (payload.workspaceId === workspaceId) {
                setState((current) => ({ ...current, unread: payload.unread }));
            }
        },
        [workspaceId],
    );

    return state.unread;
}
