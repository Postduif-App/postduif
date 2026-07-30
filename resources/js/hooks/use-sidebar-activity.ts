import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

interface ActivityPayload {
    channelId: number;
    isReply: boolean;
    mentioned: boolean;
}

export interface ChannelDelta {
    unread: number;
    mentions: number;
}

/**
 * Keep sidebar badges moving between page loads.
 *
 * The counts themselves come from the server with the page. Those are correct
 * the moment they are rendered and stale a second later, so this hook layers
 * per-channel deltas on top and drops them again as soon as fresh props
 * arrive — the server stays the source of truth, the deltas only cover the gap.
 */
export function useSidebarActivity(
    currentUserId: number,
    activeChannelId: number,
): Record<number, ChannelDelta> {
    const [deltas, setDeltas] = useState<Record<number, ChannelDelta>>({});

    useEcho<ActivityPayload>(
        `App.Models.User.${currentUserId}`,
        '.channel.activity',
        (payload) => {
            // No badge for the conversation already on screen.
            if (payload.channelId === activeChannelId) {
                return;
            }

            setDeltas((current) => {
                const existing = current[payload.channelId] ?? {
                    unread: 0,
                    mentions: 0,
                };

                return {
                    ...current,
                    [payload.channelId]: {
                        // A thread reply belongs to its thread, not to the
                        // channel's unread count — same rule as the server's.
                        unread: existing.unread + (payload.isReply ? 0 : 1),
                        mentions:
                            existing.mentions + (payload.mentioned ? 1 : 0),
                    },
                };
            });
        },
        [activeChannelId],
    );

    useEffect(() => {
        // Every Inertia response carries freshly counted badges, so whatever we
        // accumulated since the last one is now baked into the props.
        //
        // "finish" rather than "success": Inertia 3 only dispatches start,
        // progress and finish, so a listener on "success" is simply never
        // called — and the deltas would pile up forever.
        return router.on('finish', () => setDeltas({}));
    }, []);

    return deltas;
}
