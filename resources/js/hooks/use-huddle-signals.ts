import { usePresenceChannel } from '@laravel/echo-react';
import { useCallback, useEffect, useRef } from 'react';

import type { HuddleSignal } from '@/lib/huddle-signalling';
import { forMe } from '@/lib/huddle-signalling';
import type { Huddle } from '@/types/chat';

/** What a whisper carrying signalling is called on the wire. */
const SIGNAL = 'huddle-signal';

interface HuddleSignals {
    /** Send an offer, an answer or a candidate to one member. */
    send: (signal: HuddleSignal) => void;
}

/**
 * The pipe two browsers set a huddle up over.
 *
 * Client whispers on the channel's presence channel, the same road typing
 * already travels: browser → Reverb → the other browsers, without the
 * application ever seeing it. That matters here beyond speed — an SDP is a
 * description of somebody's network, and there is no reason for it to be
 * written down on a server that has no use for it.
 *
 * What this hook does not do is make connections. It carries what the peers say
 * to each other and tells the caller when the roster changed; the mesh itself
 * is the caller's business.
 */
export function useHuddleSignals(
    channelId: number,
    currentUserId: number,
    onSignal: (signal: HuddleSignal) => void,
    onRoster: (huddle: Huddle) => void,
): HuddleSignals {
    /*
     * The callbacks in a ref rather than in the effect's dependencies. Both are
     * rebuilt on every render of a component that re-renders whenever somebody
     * speaks, and a subscription that tore itself down that often would drop
     * the offer it was subscribed for.
     */
    const handleSignal = useRef(onSignal);
    const handleRoster = useRef(onRoster);

    // In an effect rather than during render, the same way useHoverShortcuts
    // keeps its actions current: writing a ref while rendering is a component
    // claiming to be pure while it is not.
    useEffect(() => {
        handleSignal.current = onSignal;
        handleRoster.current = onRoster;
    });

    // The same presence channel the conversation is already on; Echo hands out
    // one subscription per name, so this listens alongside rather than twice.
    const { channel } = usePresenceChannel(`chat.channel.${channelId}`);

    useEffect(() => {
        const subscription = channel();

        if (!subscription) {
            return;
        }

        subscription
            .listenForWhisper(SIGNAL, (signal: HuddleSignal) => {
                // A whisper reaches everybody here, so most of them are
                // somebody else's conversation — see forMe().
                if (forMe(signal, currentUserId)) {
                    handleSignal.current(signal);
                }
            })
            .listen('.huddle.updated', (huddle: Huddle) => {
                handleRoster.current(huddle);
            });

        return () => {
            subscription.stopListeningForWhisper(SIGNAL);
            subscription.stopListening('.huddle.updated');
        };
    }, [channel, currentUserId]);

    const send = useCallback(
        (signal: HuddleSignal) => {
            channel()?.whisper(SIGNAL, signal);
        },
        [channel],
    );

    return { send };
}
