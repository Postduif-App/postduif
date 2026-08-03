import { Link } from '@inertiajs/react';
import { AtSign, Bookmark, Megaphone, TicketIcon } from 'lucide-react';

import type { ComponentProps } from 'react';
import { DoveMark } from '@/components/marketing/logo';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as mentionsIndex } from '@/routes/chat/mentions';
import { index as savedIndex } from '@/routes/chat/saved';
import { index as ticketsIndex } from '@/routes/chat/tickets';
import type { ChatWorkspace } from '@/types/chat';

/**
 * The things that belong to the workspace rather than to a channel, in a rail
 * of their own.
 *
 * They used to sit at the top of the channel list, where they cost four rows of
 * a sidebar that is 16rem wide and does not grow — four rows that pushed the
 * actual channels down and were, for most of the day, not what anybody was
 * looking for. Here they take a column of icons and no vertical space at all.
 *
 * Icons only, with the name in a tooltip. That is a real trade — an icon is
 * slower to learn than a word — and it is worth it because there are four of
 * them, they never change, and the badge is what people are actually scanning
 * for.
 */
function RailButton({
    label,
    icon: Icon,
    href,
    onClick,
    active = false,
    badge = 0,
}: {
    label: string;
    icon: typeof AtSign;
    href?: ComponentProps<typeof Link>['href'];
    onClick?: () => void;
    active?: boolean;
    badge?: number;
}) {
    const className = cn(
        'relative flex size-10 items-center justify-center rounded-md transition-colors',
        active
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
    );

    const body = (
        <>
            <Icon className="size-[18px]" />
            {badge > 0 && (
                <span className="absolute top-1 right-1 min-w-4 rounded-full bg-red-500 px-1 text-[10px] leading-4 font-semibold text-white">
                    {badge}
                </span>
            )}
            <span className="sr-only">{label}</span>
        </>
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {href ? (
                    <Link href={href} className={className}>
                        {body}
                    </Link>
                ) : (
                    <button
                        type="button"
                        onClick={onClick}
                        className={className}
                    >
                        {body}
                    </button>
                )}
            </TooltipTrigger>
            {/* To the side: above would cover the rail's own neighbour. */}
            <TooltipContent side="right">{label}</TooltipContent>
        </Tooltip>
    );
}

export function WorkspaceRail({
    workspace,
    mentionTotal,
    hasTickets,
    mentionsActive = false,
    savedActive = false,
    ticketsActive = false,
    onBroadcast,
}: {
    workspace: ChatWorkspace;
    /** Summed from the same rows the channel badges come from. */
    mentionTotal: number;
    /** Whether any channel this member sees actually keeps tickets. */
    hasTickets: boolean;
    mentionsActive?: boolean;
    savedActive?: boolean;
    ticketsActive?: boolean;
    onBroadcast?: () => void;
}) {
    return (
        <nav
            aria-label="Werkbalk"
            className="flex h-full w-14 shrink-0 flex-col items-center gap-1 border-r border-sidebar-border bg-sidebar py-2"
        >
            {/*
                The mark, at the top and doing nothing.
                
                Not a link: from inside the workspace there is nowhere it would
                sensibly go — the public site is not where somebody in the
                middle of a conversation wants to end up, and a logo that
                navigates by surprise is worse than one that sits still. It is
                here because the rail is the leftmost column of the application
                and that is where a product puts its name.
            */}
            <span
                aria-hidden
                className="mb-1 flex size-10 items-center justify-center rounded-md"
                style={{
                    background: 'var(--pd-inkt)',
                    color: 'var(--pd-geel)',
                }}
            >
                <DoveMark size={20} />
            </span>

            {/*
                Always there, unlike the ticket entry below: being named is
                something that happens to everybody, and an empty list still
                answers the question it was opened with.
            */}
            <RailButton
                label="Vermeldingen"
                icon={AtSign}
                href={mentionsIndex(workspace.slug)}
                active={mentionsActive}
                badge={mentionTotal}
            />

            {workspace.features['saved-messages'] && (
                <RailButton
                    label="Bewaard"
                    icon={Bookmark}
                    href={savedIndex(workspace.slug)}
                    active={savedActive}
                />
            )}

            {/*
                Two conditions meaning different things: the feature says the
                workspace keeps tickets at all, the channels say somebody has
                actually opened one. Neither implies the other.
            */}
            {workspace.features.tickets && hasTickets && (
                <RailButton
                    label="Tickets"
                    icon={TicketIcon}
                    href={ticketsIndex(workspace.slug)}
                    active={ticketsActive}
                />
            )}

            {/*
                Pushed to the bottom: the three above are places you go, this is
                a thing you do. Grouping by that rather than by how often it is
                used keeps the rail readable as it grows.
            */}
            {onBroadcast && workspace.canBroadcastToChannels && (
                <div className="mt-auto">
                    <RailButton
                        label="Rondsturen"
                        icon={Megaphone}
                        onClick={onBroadcast}
                    />
                </div>
            )}
        </nav>
    );
}
