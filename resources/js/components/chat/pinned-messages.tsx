import { Pin, PinOff, X } from 'lucide-react';
import { useState } from 'react';

import { jumpToMessage } from '@/components/chat/message-list';
import { Button } from '@/components/ui/button';
import type { PinnedMessage } from '@/types/chat';

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function pinnedLabel(pin: PinnedMessage): string {
    const moment = pin.pinnedAt
        ? MOMENT_FORMAT.format(new Date(pin.pinnedAt))
        : null;

    if (pin.pinnedBy && moment) {
        return `Vastgepind door ${pin.pinnedBy} · ${moment}`;
    }

    return moment ? `Vastgepind op ${moment}` : 'Vastgepind';
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
                {pins.length === 1
                    ? '1 vastgepind bericht'
                    : `${pins.length} vastgepinde berichten`}
            </span>
            <span className="min-w-0 flex-1 truncate">
                <span className="font-medium text-foreground/70">
                    {first.author}
                </span>{' '}
                {first.snippet}
            </span>
            <span className="shrink-0 font-medium text-primary">Bekijken</span>
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

    return (
        <aside className="flex w-[26rem] shrink-0 flex-col border-l">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">Vastgepind</h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {pins.length === 1
                            ? '1 bericht'
                            : `${pins.length} berichten`}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    onClick={onClose}
                    aria-label="Vastgepinde berichten sluiten"
                >
                    <X className="size-4" />
                </Button>
            </header>

            <div className="flex-1 overflow-y-auto p-3">
                {pins.length === 0 ? (
                    <p className="px-1 py-6 text-center text-sm text-muted-foreground">
                        Er is niets vastgepind in dit kanaal.
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
                                    {pinnedLabel(pin)}
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
                                        Naar bericht
                                    </button>
                                    {canPin && (
                                        <button
                                            type="button"
                                            onClick={() => onUnpin(pin.id)}
                                            className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
                                        >
                                            <PinOff className="size-3" />
                                            Losmaken
                                        </button>
                                    )}
                                </div>

                                {unreachable === pin.id && (
                                    <p className="mt-2 text-[11px] text-muted-foreground">
                                        Dit bericht staat buiten het geladen
                                        deel van het kanaal. Scroll omhoog om
                                        het op te halen.
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
