import { router } from '@inertiajs/react';
import { usePresenceChannel } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/**
 * A burst of changes should cost one reload, not one per change. Somebody
 * setting a status, a priority and an assignee in a row is one gesture as far as
 * the board is concerned.
 */
const SETTLE_MS = 800;

/**
 * Keep a channel's board and the open ticket current between page loads.
 *
 * Unlike a message, a ticket cannot be patched into place from a payload:
 * whether it belongs on screen depends on the filter the reader has chosen, and
 * the counts above it are taken over every ticket in the channel rather than
 * over the rows that happen to be loaded. So this asks for the props again.
 *
 * The channel lists come along even though no badge changed — useSidebarActivity
 * throws its accumulated deltas away when any Inertia visit finishes, so asking
 * for the board alone would quietly wipe the badges of every other channel.
 */
export function useTicketActivity(channelId: number, enabled: boolean): void {
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);

    // The same presence channel the conversation is already on; Echo hands out
    // one subscription per name, so this listens alongside rather than twice.
    const { channel } = usePresenceChannel(`chat.channel.${channelId}`);

    useEffect(() => {
        const subscription = channel();

        if (!subscription || !enabled) {
            return;
        }

        subscription.listen('.ticket.updated', () => {
            // A reload is already queued: this change will be in it.
            if (pending.current !== null) {
                return;
            }

            pending.current = setTimeout(() => {
                pending.current = null;

                router.reload({
                    only: ['tickets', 'ticket', 'channels', 'directMessages'],
                });
            }, SETTLE_MS);
        });

        return () => {
            if (pending.current !== null) {
                clearTimeout(pending.current);
                pending.current = null;
            }

            subscription.stopListening('.ticket.updated');
        };
    }, [channel, enabled]);
}
