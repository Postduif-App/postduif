import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';

import { playNotificationSound } from '@/lib/notification-sound';

interface InboxPayload {
    workspaceId: number;
    unread: number;
}

/**
 * Whether a number arriving over the socket is news worth hearing.
 *
 * Only upwards. The count falls as rows are read — on this screen or on a phone
 * somewhere else — and a chime for something going away would be a lie about
 * what happened.
 */
export function inboxRose(held: number, incoming: number): boolean {
    return incoming > held;
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

    /*
     * What the last chime decision was measured against.
     *
     * A ref rather than the state above, because the sound is decided in an
     * event handler and the state there is whatever the render that installed
     * the handler closed over. Kept in step from an effect, so nothing is
     * written during render.
     */
    const heard = useRef(state.unread);

    useEffect(() => {
        heard.current = state.unread;
    }, [state.unread]);

    useEcho<InboxPayload>(
        `App.Models.User.${currentUserId}`,
        '.inbox.updated',
        (payload) => {
            // One socket carries every workspace this member belongs to, and
            // the badge on screen speaks for one of them.
            if (payload.workspaceId === workspaceId) {
                /*
                 * The socket is the only path that rings. A page load arrives
                 * with a count too, and that count is usually not zero — so
                 * chiming on "the number changed" would sound on every
                 * navigation through a workspace with anything waiting in it,
                 * which is noise about nothing new.
                 */
                if (inboxRose(heard.current, payload.unread)) {
                    playNotificationSound();
                }

                heard.current = payload.unread;

                setState((current) => ({ ...current, unread: payload.unread }));
            }
        },
        [workspaceId],
    );

    return state.unread;
}
