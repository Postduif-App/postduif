import { usePresenceChannel } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

/**
 * Who is in the workspace right now.
 *
 * Its own subscription rather than something derived from the channel rosters
 * the conversation already keeps: those only know about people who opened the
 * same channel you did. Somebody working two channels over is online, and a
 * list that called them away would be wrong about the one thing it exists to
 * say.
 *
 * Returns a set of ids. The names, faces and statuses come from the page — this
 * answers "is this person here", and nothing else.
 */
export function useWorkspacePresence(workspaceSlug: string): Set<number> {
    const [present, setPresent] = useState<Set<number>>(new Set());

    const { channel } = usePresenceChannel(`chat.workspace.${workspaceSlug}`);

    useEffect(() => {
        const subscription = channel();

        if (!subscription) {
            return;
        }

        subscription
            .here((users: { id: number }[]) =>
                setPresent(new Set(users.map((user) => user.id))),
            )
            .joining((user: { id: number }) =>
                setPresent((current) => new Set(current).add(user.id)),
            )
            .leaving((user: { id: number }) =>
                setPresent((current) => {
                    const next = new Set(current);

                    next.delete(user.id);

                    return next;
                }),
            );

        /*
         * No teardown. here/joining/leaving are the subscription's own
         * lifecycle rather than named events, so there is nothing to unbind —
         * echo-react leaves the channel when the hook goes away, which is what
         * actually stops them.
         */
    }, [channel]);

    return present;
}
