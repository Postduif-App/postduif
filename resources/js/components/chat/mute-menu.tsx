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
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { mute, unmute } from '@/routes/chat/channels';
import type { ActiveChannel, ChatWorkspace } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

/**
 * How long quiet lasts. Hours rather than a moment to pick: what somebody is
 * deciding is "leave me alone for a bit", and a date picker turns that into a
 * small chore.
 *
 * Keys rather than words: this is a module constant, and a hook cannot be
 * called from one — so the lookup happens where t() is in reach.
 */
const DURATIONS: { hours: number | null; label: TranslationKey }[] = [
    { hours: 1, label: 'chat_ui.mute.duration.hour' },
    { hours: 8, label: 'chat_ui.mute.duration.workday' },
    { hours: 24, label: 'chat_ui.mute.duration.tomorrow' },
    { hours: 168, label: 'chat_ui.mute.duration.week' },
    { hours: null, label: 'chat_ui.mute.duration.forever' },
];

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
    const formats = useFormats();
    const { t } = useTranslate();
    const muted = channel.mutedUntil !== null;
    const target = { workspace: workspace.slug, channel: channel.id };

    const label = !muted
        ? t('chat_ui.mute.action')
        : channel.mutedUntil === 'forever'
          ? t('chat_ui.mute.until_forever')
          : t('chat_ui.mute.until', {
                moment: formats.dayTime.format(
                    new Date(channel.mutedUntil as string),
                ),
            });

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
                            {t('chat_ui.mute.unmute')}
                        </DropdownMenuItem>
                    </>
                ) : (
                    <>
                        <DropdownMenuLabel className="font-normal text-muted-foreground">
                            {t('chat_ui.mute.heading')}
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
                                {t(duration.label)}
                            </DropdownMenuItem>
                        ))}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
