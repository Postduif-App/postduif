import { Link } from '@inertiajs/react';
import type { AtSign } from 'lucide-react';
import {
    Bookmark,
    ClipboardList,
    Clock,
    Inbox,
    KeyRound,
    Megaphone,
    Menu,
    Pin,
    Send,
    TicketIcon,
} from 'lucide-react';

import type { ComponentProps } from 'react';
import { DoveMark } from '@/components/marketing/logo';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as boardIndex } from '@/routes/chat/board';
import { index as formsIndex } from '@/routes/chat/forms';
import { index as inboxIndex } from '@/routes/chat/inbox';
import { index as savedIndex } from '@/routes/chat/saved';
import { index as secretsIndex } from '@/routes/chat/sent-secrets';
import { index as ticketsIndex } from '@/routes/chat/tickets';
import { index as timeclockIndex } from '@/routes/chat/timeclock';
import { index as transfersIndex } from '@/routes/chat/transfers';
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
    inboxTotal,
    hasTickets,
    hasTransfers,
    boardActive = false,
    secretsActive = false,
    mentionsActive = false,
    savedActive = false,
    transfersActive = false,
    ticketsActive = false,
    formsActive = false,
    timeclockActive = false,
    onBroadcast,
    onOpenChannels,
}: {
    workspace: ChatWorkspace;
    /** Everything unread in the inbox, of every kind. */
    inboxTotal: number;
    /** Whether any channel this member sees actually keeps tickets. */
    hasTickets: boolean;
    /** Whether this workspace has file sending switched on at all. */
    hasTransfers: boolean;
    boardActive?: boolean;
    secretsActive?: boolean;
    mentionsActive?: boolean;
    savedActive?: boolean;
    transfersActive?: boolean;
    ticketsActive?: boolean;
    formsActive?: boolean;
    timeclockActive?: boolean;
    onBroadcast?: () => void;
    /**
     * Opens the channel list, on a screen too narrow to keep it standing.
     * Absent above lg, where the list is simply there.
     */
    onOpenChannels?: () => void;
}) {
    const { t } = useTranslate();

    return (
        <nav
            aria-label={t('sidebar.toolbar')}
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
            {/*
                The way to the channel list on a phone, above the mark rather
                than in the conversation's header: the rail is what stays put on
                every one of these screens, and a hamburger repeated in twelve
                headers is twelve places to forget one.
            */}
            {onOpenChannels && (
                <button
                    type="button"
                    onClick={onOpenChannels}
                    aria-label={t('sidebar.rail.channels')}
                    className="mb-1 flex size-10 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground focus-visible:ring-2 focus-visible:outline-none lg:hidden"
                >
                    <Menu className="size-5" />
                </button>
            )}

            <span
                aria-hidden
                /*
                    The bird on its own, in the theme's accent — no tile behind
                    it. It used to sit on a block of huisstijl ink with the
                    yellow dove on top, which made the rail's first element the
                    heaviest thing on the screen and, being a fixed palette,
                    the one element ignoring the accent a beheerder picked.
                    --primary is what BuildThemeStyles rewrites per workspace,
                    so the mark now follows along without knowing a theme
                    exists.
                */
                className="mb-1 flex size-10 items-center justify-center text-primary"
            >
                <DoveMark size={24} />
            </span>

            {/*
                Always there, unlike the ticket entry below: being named is
                something that happens to everybody, and an empty list still
                answers the question it was opened with.
            */}
            <RailButton
                label={t('sidebar.rail.inbox')}
                icon={Inbox}
                href={inboxIndex(workspace.slug)}
                active={mentionsActive}
                badge={inboxTotal}
            />

            {/*
                One condition, unlike the two tickets needs below, and not
                because the board is simpler: workspace.board already carries
                both halves — the feature and this member's role. It has to,
                because the second half is a guest, and a guest is exactly the
                reader who must not be handed the parts and trusted to combine
                them.
            */}
            {workspace.board && (
                <RailButton
                    label={t('sidebar.rail.board')}
                    icon={Pin}
                    href={boardIndex(workspace.slug)}
                    active={boardActive}
                />
            )}

            {/*
                Alongside the board rather than only inside a channel: a link
                made here belongs to no conversation, and the list behind it is
                where somebody checks whether one was ever picked up.

                One condition, because workspace.secrets already carries both
                halves — the workspace has the feature, and this member may use
                it. See BuildChatShell.
            */}
            {workspace.secrets && (
                <RailButton
                    label={t('sidebar.rail.secrets')}
                    icon={KeyRound}
                    href={secretsIndex(workspace.slug)}
                    active={secretsActive}
                />
            )}

            {workspace.features['saved-messages'] && (
                <RailButton
                    label={t('sidebar.rail.saved')}
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
                    label={t('sidebar.rail.tickets')}
                    icon={TicketIcon}
                    href={ticketsIndex(workspace.slug)}
                    active={ticketsActive}
                />
            )}

            {/*
                One condition rather than the two tickets needs: an empty list
                here is still worth opening, because the screen is also where a
                transfer is made. Tickets are only ever read on that screen.
            */}
            {hasTransfers && (
                <RailButton
                    label={t('sidebar.rail.transfers')}
                    icon={Send}
                    href={transfersIndex(workspace.slug)}
                    active={transfersActive}
                />
            )}

            {/*
                The forms this workspace keeps. Beside sending rather than in
                settings alone, because writing one is work somebody does in the
                middle of their day — the same reason the transfer list sits
                here rather than behind a settings screen.

                One condition, because workspace.forms already carries both
                halves — the workspace has the feature and this member may write
                one. See BuildChatShell.
            */}
            {workspace.forms && (
                <RailButton
                    label={t('sidebar.rail.forms')}
                    icon={ClipboardList}
                    href={formsIndex(workspace.slug)}
                    active={formsActive}
                />
            )}

            {/*
                The clock, under the forms.

                One condition, as with forms: workspace.timeclock already
                carries the feature and the role — and the role half is what
                keeps it away from a guest, whose working day belongs to
                somebody else's company.
            */}
            {workspace.timeclock && (
                <RailButton
                    label={t('sidebar.rail.timeclock')}
                    icon={Clock}
                    href={timeclockIndex(workspace.slug)}
                    active={timeclockActive}
                />
            )}

            {/*
                Pushed to the bottom: the ones above are places you go, this is
                a thing you do. Grouping by that rather than by how often it is
                used keeps the rail readable as it grows.
            */}
            {onBroadcast && workspace.canBroadcastToChannels && (
                <div className="mt-auto">
                    <RailButton
                        label={t('sidebar.rail.broadcast')}
                        icon={Megaphone}
                        onClick={onBroadcast}
                    />
                </div>
            )}
        </nav>
    );
}
