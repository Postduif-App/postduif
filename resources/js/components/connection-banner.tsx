import { PlugZapIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useSocketOffline } from '@/hooks/use-socket-offline';
import { useTranslate } from '@/hooks/use-translate';

/**
 * A bar over the app when the chat has stopped hearing from the server.
 *
 * Worth the screen space because the failure is silent otherwise: with the
 * socket down every screen keeps drawing exactly what it drew a minute ago —
 * no new messages, no typing, no presence — and looks indistinguishable from a
 * quiet afternoon. This is the only thing that tells the difference.
 *
 * Fixed rather than in the flow: the chat is a full-height grid of panes that
 * each scroll on their own, and pushing all of that down by a bar's height on a
 * wifi hop would move the message somebody was reading.
 */
export function ConnectionBanner() {
    const { t } = useTranslate();
    const offline = useSocketOffline();

    if (!offline) {
        return null;
    }

    return (
        <div
            /*
             * Announced rather than shouted: assertive would cut into whatever
             * a screen reader is in the middle of, and this is a bar that will
             * still be there when the sentence is finished.
             */
            role="status"
            aria-live="polite"
            className="fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm text-amber-950 shadow-md dark:bg-amber-400"
        >
            <PlugZapIcon className="size-4 shrink-0" aria-hidden="true" />
            <span className="min-w-0">{t('chat_ui.connection.offline')}</span>
            <Button
                size="sm"
                variant="outline"
                className="h-7 border-amber-950/30 bg-transparent text-amber-950 hover:bg-amber-950/10 hover:text-amber-950"
                onClick={() => window.location.reload()}
            >
                {t('chat_ui.connection.reload')}
            </Button>
        </div>
    );
}
