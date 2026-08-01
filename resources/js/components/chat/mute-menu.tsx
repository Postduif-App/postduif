import { router } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { mute, unmute } from '@/routes/chat/channels';
import type { ActiveChannel, ChatWorkspace } from '@/types/chat';

/**
 * How long quiet lasts. Hours rather than a moment to pick: what somebody is
 * deciding is "leave me alone for a bit", and a date picker turns that into a
 * small chore.
 */
const DURATIONS: { hours: number | null; label: string }[] = [
    { hours: 1, label: 'Een uur' },
    { hours: 8, label: 'De rest van de werkdag' },
    { hours: 24, label: 'Tot morgen' },
    { hours: 168, label: 'Een week' },
    { hours: null, label: 'Tot ik het weer aanzet' },
];

const UNTIL_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Turning a channel's notifications off, for yourself.
 *
 * In the header rather than in the channel settings: muting is not a setting of
 * the channel — the person beside you may want it loud — and burying it behind
 * a dialog that only channel managers can open would put it out of reach of
 * exactly the people who need it.
 */
export function MuteMenu({
    workspace,
    channel,
}: {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
}) {
    const muted = channel.mutedUntil !== null;
    const target = { workspace: workspace.slug, channel: channel.id };

    const label = !muted
        ? 'Meldingen dempen'
        : channel.mutedUntil === 'forever'
          ? 'Gedempt totdat je het weer aanzet'
          : `Gedempt tot ${UNTIL_FORMAT.format(new Date(channel.mutedUntil as string))}`;

    return (
        <DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            aria-label={label}
                            className={cn(
                                'rounded-md border p-1.5 transition-colors hover:bg-muted hover:text-foreground',
                                muted
                                    ? 'border-amber-500/40 text-amber-600 dark:text-amber-400'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {muted ? (
                                <BellOff className="size-3.5" />
                            ) : (
                                <Bell className="size-3.5" />
                            )}
                        </button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>{label}</TooltipContent>
            </Tooltip>

            <DropdownMenuContent align="end" className="w-56">
                {muted ? (
                    <>
                        <DropdownMenuLabel className="font-normal text-muted-foreground">
                            {label}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="cursor-pointer"
                            onSelect={() =>
                                router.delete(unmute.url(target), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Bell className="size-4" />
                            Meldingen weer aanzetten
                        </DropdownMenuItem>
                    </>
                ) : (
                    <>
                        <DropdownMenuLabel className="font-normal text-muted-foreground">
                            Dit kanaal stil houden
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {DURATIONS.map((duration) => (
                            <DropdownMenuItem
                                key={duration.label}
                                className="cursor-pointer"
                                onSelect={() =>
                                    router.post(
                                        mute.url(target),
                                        // Absent rather than null: the endpoint
                                        // reads a missing field as "no end".
                                        duration.hours === null
                                            ? {}
                                            : { hours: duration.hours },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {duration.label}
                            </DropdownMenuItem>
                        ))}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
