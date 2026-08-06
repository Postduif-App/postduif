import { Pin, PinOff, X } from 'lucide-react';
import { useState } from 'react';

import { jumpToMessage } from '@/components/chat/message-list';
import { Button } from '@/components/ui/button';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import type { PinnedMessage } from '@/types/chat';

function pinnedLabel(
    pin: PinnedMessage,
    // Handed in rather than looked up: this is a plain function, and neither a
    // formatter nor a line of text can be reached from one without a hook.
    dateTime: Intl.DateTimeFormat,
    t: ReturnType<typeof useTranslate>['t'],
): string {
    const moment = pin.pinnedAt
        ? dateTime.format(new Date(pin.pinnedAt))
        : null;

    if (pin.pinnedBy && moment) {
        return t('panelen.pinned.by', { who: pin.pinnedBy, moment });
    }

    return moment
        ? t('panelen.pinned.at', { moment })
        : t('panelen.pinned.title');
}

/**
 * The strip above the conversation saying that this channel has something
 * pinned.
 *
 * One line, and the oldest pin in it: pins are read as a list rather than as a
 * feed, so the first thing put up — the intro, the rules — is the one worth
 * showing when there is room for exactly one. The rest is a click away.
 *
 * Nothing at all when the channel has no pins. An empty bar would take a strip
 * of every channel forever to say that nothing is happening.
 */
export function PinnedBar({
    pins,
    onOpen,
}: {
    pins: PinnedMessage[];
    onOpen: () => void;
}) {
    // Above the early return, where every hook has to be: React counts them in
    // order, and one that only runs on the channels with a pin would be a
    // different count from one render to the next.
    const { t, tChoice } = useTranslate();

    if (pins.length === 0) {
        return null;
    }

    const [first] = pins;

    return (
        <button
            type="button"
            onClick={onOpen}
            className="flex h-9 w-full shrink-0 items-center gap-2 border-b bg-muted/40 px-4 text-left text-xs text-muted-foreground transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset"
        >
            <Pin className="size-3.5 shrink-0 text-primary" aria-hidden />
            <span className="shrink-0 font-medium text-foreground">
                {tChoice('panelen.pinned.count', pins.length)}
            </span>
            <span className="min-w-0 flex-1 truncate">
                <span className="font-medium text-foreground/70">
                    {first.author}
                </span>{' '}
                {first.snippet}
            </span>
            <span className="shrink-0 font-medium text-primary">
                {t('panelen.pinned.view')}
            </span>
        </button>
    );
}

/**
 * The full list, in the same slot as the thread and ticket panels.
 *
 * Unpinning lives here rather than only on the message row: what is pinned may
 * be months old, and having to find it in the channel first to take it down
 * again would make the list impossible to keep tidy.
 */
export function PinnedPanel({
    pins,
    canPin,
    onUnpin,
    onClose,
}: {
    pins: PinnedMessage[];
    canPin: boolean;
    onUnpin: (id: string) => void;
    onClose: () => void;
}) {
    /**
     * The pin somebody tried to jump to that is not on screen. The channel only
     * holds its last fifty messages, and an old pin is simply not among them —
     * saying so is better than a click that appears to do nothing.
     */
    const [unreachable, setUnreachable] = useState<string | null>(null);
    const formats = useFormats();
    const { t, tChoice } = useTranslate();

    /*
     * Beside the conversation on a wide screen; over it on one too narrow to
     * hold both. Anchored at the rail rather than at the edge, so the way back
     * to the channel list stays reachable while a panel is open.
     */
    return (
        <aside className="fixed inset-y-0 right-0 left-14 z-30 flex flex-col border-l bg-background lg:static lg:left-auto lg:w-[26rem] lg:shrink-0">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">
                        {t('panelen.pinned.title')}
                    </h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {tChoice('panelen.pinned.messages', pins.length)}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    onClick={onClose}
                    aria-label={t('panelen.pinned.close')}
                >
                    <X className="size-4" />
                </Button>
            </header>

            <div className="flex-1 overflow-y-auto p-3">
                {pins.length === 0 ? (
                    <p className="px-1 py-6 text-center text-sm text-muted-foreground">
                        {t('panelen.pinned.empty')}
                    </p>
                ) : (
                    <ul className="flex flex-col gap-2">
                        {pins.map((pin) => (
                            <li
                                key={pin.id}
                                className="rounded-md border p-3 transition-colors hover:bg-muted/40"
                            >
                                <p className="text-xs font-semibold">
                                    {pin.author}
                                </p>
                                <p className="mt-1 text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground/90">
                                    {pin.snippet}
                                </p>
                                <p className="mt-2 text-[11px] text-muted-foreground">
                                    {pinnedLabel(pin, formats.dateTime, t)}
                                </p>

                                <div className="mt-2 flex items-center gap-3 text-xs">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setUnreachable(
                                                jumpToMessage(pin.id)
                                                    ? null
                                                    : pin.id,
                                            )
                                        }
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {t('panelen.pinned.jump')}
                                    </button>
                                    {canPin && (
                                        <button
                                            type="button"
                                            onClick={() => onUnpin(pin.id)}
                                            className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
                                        >
                                            <PinOff className="size-3" />
                                            {t('panelen.pinned.unpin')}
                                        </button>
                                    )}
                                </div>

                                {unreachable === pin.id && (
                                    <p className="mt-2 text-[11px] text-muted-foreground">
                                        {t('panelen.pinned.unreachable')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </aside>
    );
}
