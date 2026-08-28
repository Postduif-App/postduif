import { router } from '@inertiajs/react';
import { Bell, BellOff, Check, Zap } from 'lucide-react';

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
import { instantNotifications, mute, unmute } from '@/routes/chat/channels';
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
    // Meaningless while muted — a mute wins over it either way — so only read
    // once we know the channel is not quiet.
    const instantActive = !muted && channel.instantNotifications === true;
    const target = { workspace: workspace.slug, channel: channel.id };

    const label = muted
        ? channel.mutedUntil === 'forever'
            ? t('chat_ui.mute.until_forever')
            : t('chat_ui.mute.until', {
                  moment: formats.dayTime.format(
                      new Date(channel.mutedUntil as string),
                  ),
              })
        : instantActive
          ? t('chat_ui.mute.instant_active')
          : t('chat_ui.mute.action');

    const setInstant = (value: boolean | null) =>
        router.put(
            instantNotifications.url(target),
            // Absent rather than null: the endpoint reads a missing field as
            // "follow the account default".
            value === null ? {} : { instant: value },
            { preserveScroll: true },
        );

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
                                muted &&
                                    'border-amber-500/40 text-amber-600 dark:text-amber-400',
                                instantActive &&
                                    'border-sky-500/40 text-sky-600 dark:text-sky-400',
                                !muted &&
                                    !instantActive &&
                                    'text-muted-foreground',
                            )}
                        >
                            {muted ? (
                                <BellOff className="size-3.5" />
                            ) : instantActive ? (
                                <Zap className="size-3.5" />
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
                            {t('chat_ui.mute.instant_heading')}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            className="cursor-pointer"
                            onSelect={() => setInstant(true)}
                        >
                            {channel.instantNotifications === true ? (
                                <Check className="size-4" />
                            ) : (
                                <span className="size-4" />
                            )}
                            {t('chat_ui.mute.instant_all')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            className="cursor-pointer"
                            onSelect={() => setInstant(null)}
                        >
                            {channel.instantNotifications === null ? (
                                <Check className="size-4" />
                            ) : (
                                <span className="size-4" />
                            )}
                            {t('chat_ui.mute.instant_default')}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
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
