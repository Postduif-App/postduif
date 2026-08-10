import { router } from '@inertiajs/react';
import { usePresenceChannel } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Longer than the ticket board's settle, because the traffic is different in
 * kind: a document autosaves every 800 ms of quiet, so a colleague writing a
 * paragraph produces a steady trickle rather than the burst a ticket does.
 */
const SETTLE_MS = 2000;

/**
 * Keep a channel's document list current, and notice when the open one moves.
 *
 * The list is reloaded, the open document deliberately is not — and that is the
 * whole design of this hook.
 *
 * A ticket board can simply be asked for again: nobody is mid-sentence in it. A
 * document is the opposite. Reloading it would replace the editor's value under
 * whoever is typing, taking their caret, their selection and their undo history
 * with it — and would do so precisely when the document is busiest, which is
 * exactly when two people are working in it. Stale content on screen is a
 * nuisance; content that rearranges itself while you write is unusable.
 *
 * So the open document only reports that it has moved, and the person decides
 * when to take the new one. See the notice in document-view.
 *
 * @param openNumber The document open right now, or null on the list.
 * @returns Whether the open document has been changed by somebody else since it
 *          was loaded.
 */
export function useDocumentActivity(
    channelId: number,
    enabled: boolean,
    openNumber: number | null,
): { openDocumentStale: boolean; dismiss: () => void } {
    const pending = useRef<ReturnType<typeof setTimeout> | null>(null);

    /*
     * Which document has moved, not whether one has.
     *
     * Storing the number means the answer for "is the open one stale" is
     * derived rather than kept in step: opening another document or going back
     * to the list makes it false by itself, with no effect to reset it — and an
     * effect that resets state is both a cascading render and a thing that can
     * be forgotten.
     */
    const [staleNumber, setStaleNumber] = useState<number | null>(null);

    const openDocumentStale = openNumber !== null && staleNumber === openNumber;

    // The same presence channel the conversation is already on; Echo hands out
    // one subscription per name, so this listens alongside rather than twice.
    const { channel } = usePresenceChannel(`chat.channel.${channelId}`);

    const dismiss = useCallback(() => setStaleNumber(null), []);

    useEffect(() => {
        const subscription = channel();

        if (!subscription || !enabled) {
            return;
        }

        subscription.listen(
            '.documents.updated',
            (event: { number: number }) => {
                if (event.number === openNumber) {
                    setStaleNumber(event.number);

                    /*
                     * And nothing else. Asking for documentList here would be
                     * harmless in itself, but Inertia's partial reload replaces
                     * every prop it fetches — and `document` would come along on
                     * any later reload that forgets to exclude it. Keeping the
                     * two paths apart is what makes that impossible rather than
                     * unlikely.
                     */
                    return;
                }

                if (pending.current !== null) {
                    return;
                }

                pending.current = setTimeout(() => {
                    pending.current = null;

                    /*
                     * The list, and never `document`. The channel lists come along
                     * for the same reason the ticket hook takes them —
                     * useSidebarActivity throws its accumulated deltas away when
                     * any Inertia visit finishes, so asking for the documents
                     * alone would quietly wipe every other channel's badge.
                     */
                    router.reload({
                        only: ['documentList', 'channels', 'directMessages'],
                        /*
                         * Nobody asked for this — it is a colleague's save arriving
                         * over the socket. A progress bar would report somebody
                         * else's typing as if this page were busy.
                         */
                        showProgress: false,
                    });
                }, SETTLE_MS);
            },
        );

        return () => {
            if (pending.current !== null) {
                clearTimeout(pending.current);
                pending.current = null;
            }

            subscription.stopListening('.documents.updated');
        };
    }, [channel, enabled, openNumber]);

    return { openDocumentStale, dismiss };
}
