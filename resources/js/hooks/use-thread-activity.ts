import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

interface ActivityPayload {
    channelId: number;
    isReply: boolean;
    mentioned: boolean;
}

/**
 * A burst of replies should cost one reload, not one per message. Long enough
 * to swallow a quick back-and-forth, short enough that the sidebar still feels
 * live.
 */
const SETTLE_MS = 1000;

/**
 * Keep the sidebar's thread list current between page loads.
 *
 * Unlike the badge counts next to it, a thread row cannot be patched in the
 * browser: whether a thread belongs in the list depends on the window, on what
 * the member closed, and on which channels they may see — all of which live on
 * the server. So this asks for the prop again rather than guessing at it.
 *
 * Deliberately not skipping the channel that is currently open. A badge is
 * suppressed there because you are already looking at the messages, but a
 * thread reply is exactly what you do not see while reading the channel.
 */
export function useThreadActivity(currentUserId: number): void {
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEcho<ActivityPayload>(
        `App.Models.User.${currentUserId}`,
        '.channel.activity',
        (payload) => {
            // Only replies make or move a thread; a root message cannot.
            if (!payload.isReply) {
                return;
            }

            // A reload is already queued: this reply will be in it.
            if (pending.current !== null) {
                return;
            }

            pending.current = setTimeout(() => {
                pending.current = null;

                // reload() keeps scroll position and component state on its
                // own — the options are not even accepted here — so the
                // conversation the member is reading stays exactly as it was.
                //
                // The channel lists come along even though no badge changed:
                // useSidebarActivity throws its accumulated deltas away when
                // any Inertia visit finishes, on the assumption that whatever
                // it counted is now baked into the props. Asking for
                // activeThreads alone would break that assumption and quietly
                // wipe the badges of every other channel.
                router.reload({
                    only: ['activeThreads', 'channels', 'directMessages'],
                });
            }, SETTLE_MS);
        },
        [],
    );

    useEffect(
        () => () => {
            if (pending.current !== null) {
                clearTimeout(pending.current);
            }
        },
        [],
    );
}
