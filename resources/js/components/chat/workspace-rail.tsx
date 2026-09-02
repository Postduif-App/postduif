import { Link } from '@inertiajs/react';
import type { AtSign } from 'lucide-react';
import {
    Bookmark,
    ClipboardList,
    Clock,
    FileSignature,
    FileUp,
    Inbox,
    KeyRound,
    Megaphone,
    MessagesSquare,
    Pin,
    TicketIcon,
} from 'lucide-react';

import type { ComponentProps, ReactNode } from 'react';
import { DoveMark } from '@/components/marketing/logo';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as chatIndex } from '@/routes/chat';
import { index as boardIndex } from '@/routes/chat/board';
import { index as contractsIndex } from '@/routes/chat/contracts';
import { index as formsIndex } from '@/routes/chat/forms';
import { index as inboxIndex } from '@/routes/chat/inbox';
import { index as savedIndex } from '@/routes/chat/saved';
import { index as secretsIndex } from '@/routes/chat/sent-secrets';
import { index as ticketsIndex } from '@/routes/chat/tickets';
import { index as timeclockIndex } from '@/routes/chat/timeclock';
import { index as transfersIndex } from '@/routes/chat/transfers';
import type { ChatWorkspace } from '@/types/chat';

/**
 * The things that belong to the workspace rather than to a channel.
 *
 * They used to sit at the top of the channel list, where they cost four rows of
 * a sidebar that is 16rem wide and does not grow — four rows that pushed the
 * actual channels down and were, for most of the day, not what anybody was
 * looking for.
 *
 * On a wide screen they are a column of icons down the left edge and take no
 * vertical space at all. Below lg there is no room for a column of anything, so
 * the same entries turn up by name inside the menu — see WorkspaceToolLinks.
 * One list feeds both, because two hand-maintained copies of "what does this
 * workspace offer" would start to differ the first time either is touched.
 */
interface WorkspaceToolsProps {
    workspace: ChatWorkspace;
    /** Everything unread in the inbox, of every kind. */
    inboxTotal: number;
    /** Whether any channel this member sees actually keeps tickets. */
    hasTickets: boolean;
    /** Whether this workspace has file sending switched on at all. */
    hasTransfers: boolean;
    /** Whether a channel is open, which is what the chat entry marks. */
    chatActive?: boolean;
    boardActive?: boolean;
    secretsActive?: boolean;
    mentionsActive?: boolean;
    savedActive?: boolean;
    transfersActive?: boolean;
    ticketsActive?: boolean;
    formsActive?: boolean;
    contractsActive?: boolean;
    timeclockActive?: boolean;
    onBroadcast?: () => void;
}

interface ToolEntry {
    key: string;
    label: string;
    icon: typeof AtSign;
    href?: ComponentProps<typeof Link>['href'];
    onClick?: () => void;
    active: boolean;
    badge: number;
    /**
     * Whether this is a thing you do rather than a place you go. The rail
     * pushes those to the bottom and the menu puts them under a rule —
     * grouping by that rather than by how often something is used is what keeps
     * either list readable as it grows.
     */
    isAction?: boolean;
}

function toolEntries(
    {
        workspace,
        inboxTotal,
        hasTickets,
        hasTransfers,
        chatActive = false,
        boardActive = false,
        secretsActive = false,
        mentionsActive = false,
        savedActive = false,
        transfersActive = false,
        ticketsActive = false,
        formsActive = false,
        contractsActive = false,
        timeclockActive = false,
        onBroadcast,
    }: WorkspaceToolsProps,
    t: ReturnType<typeof useTranslate>['t'],
): ToolEntry[] {
    const entries: ToolEntry[] = [];

    /*
        The chat itself, first and unconditional.

        The rail grew from the things beside the conversation, so it never held
        an entry for the conversation — which was fine while the rail stood next
        to it and read as its own margin. It stopped being fine once the other
        entries got screens of their own: from the contract list, the form list
        or the clock, every icon in the column led somewhere further away, and
        the way back to the talking was the browser's back button or the
        workspace name at the top of the sheet.

        The address is the workspace root rather than a channel, because which
        channel is not a question the browser can answer: chat.index picks the
        one this member spoke in most recently, of those they may see, and makes
        an empty workspace a channel before it answers. See ChatController.
    */
    entries.push({
        key: 'chat',
        label: t('sidebar.rail.chat'),
        icon: MessagesSquare,
        href: chatIndex(workspace.slug),
        active: chatActive,
        badge: 0,
    });

    /*
        Always there, unlike the ticket entry below: being named is something
        that happens to everybody, and an empty list still answers the question
        it was opened with.
    */
    entries.push({
        key: 'inbox',
        label: t('sidebar.rail.inbox'),
        icon: Inbox,
        href: inboxIndex(workspace.slug),
        active: mentionsActive,
        badge: inboxTotal,
    });

    /*
        One condition, unlike the two tickets needs below, and not because the
        board is simpler: workspace.board already carries both halves — the
        feature and this member's role. It has to, because the second half is a
        guest, and a guest is exactly the reader who must not be handed the
        parts and trusted to combine them.
    */
    if (workspace.board) {
        entries.push({
            key: 'board',
            label: t('sidebar.rail.board'),
            icon: Pin,
            href: boardIndex(workspace.slug),
            active: boardActive,
            badge: 0,
        });
    }

    /*
        Alongside the board rather than only inside a channel: a link made here
        belongs to no conversation, and the list behind it is where somebody
        checks whether one was ever picked up.

        One condition, because workspace.secrets already carries both halves —
        the workspace has the feature, and this member may use it. See
        BuildChatShell.
    */
    if (workspace.secrets) {
        entries.push({
            key: 'secrets',
            label: t('sidebar.rail.secrets'),
            icon: KeyRound,
            href: secretsIndex(workspace.slug),
            active: secretsActive,
            badge: 0,
        });
    }

    if (workspace.features['saved-messages']) {
        entries.push({
            key: 'saved',
            label: t('sidebar.rail.saved'),
            icon: Bookmark,
            href: savedIndex(workspace.slug),
            active: savedActive,
            badge: 0,
        });
    }

    /*
        Two conditions meaning different things: the feature says the workspace
        keeps tickets at all, the channels say somebody has actually opened one.
        Neither implies the other.
    */
    if (workspace.features.tickets && hasTickets) {
        entries.push({
            key: 'tickets',
            label: t('sidebar.rail.tickets'),
            icon: TicketIcon,
            href: ticketsIndex(workspace.slug),
            active: ticketsActive,
            badge: 0,
        });
    }

    /*
        One condition rather than the two tickets needs: an empty list here is
        still worth opening, because the screen is also where a transfer is
        made. Tickets are only ever read on that screen.
    */
    if (hasTransfers) {
        entries.push({
            key: 'transfers',
            label: t('sidebar.rail.transfers'),
            icon: FileUp,
            href: transfersIndex(workspace.slug),
            active: transfersActive,
            badge: 0,
        });
    }

    /*
        The forms this workspace keeps. Beside sending rather than in settings
        alone, because writing one is work somebody does in the middle of their
        day — the same reason the transfer list sits here rather than behind a
        settings screen.

        One condition, because workspace.forms already carries both halves — the
        workspace has the feature and this member may write one.
    */
    if (workspace.forms) {
        entries.push({
            key: 'forms',
            label: t('sidebar.rail.forms'),
            icon: ClipboardList,
            href: formsIndex(workspace.slug),
            active: formsActive,
            badge: 0,
        });
    }

    /*
        The contracts this workspace has out.

        Beside the forms rather than under settings, and for a sharper version
        of the same reason: a contract is not a thing you configure once, it is
        a thing you send on a Tuesday and then go back to twice a day to see who
        has signed.

        One condition, as with forms: workspace.contracts already carries both
        halves — the feature is on and this role may send one.
    */
    if (workspace.contracts) {
        entries.push({
            key: 'contracts',
            label: t('sidebar.rail.contracts'),
            icon: FileSignature,
            href: contractsIndex(workspace.slug),
            active: contractsActive,
            badge: 0,
        });
    }

    /*
        The clock, under the forms.

        One condition, as with forms: workspace.timeclock already carries the
        feature and the role — and the role half is what keeps it away from a
        guest, whose working day belongs to somebody else's company.
    */
    if (workspace.timeclock) {
        entries.push({
            key: 'timeclock',
            label: t('sidebar.rail.timeclock'),
            icon: Clock,
            href: timeclockIndex(workspace.slug),
            active: timeclockActive,
            badge: 0,
        });
    }

    if (onBroadcast && workspace.canBroadcastToChannels) {
        entries.push({
            key: 'broadcast',
            label: t('sidebar.rail.broadcast'),
            icon: Megaphone,
            onClick: onBroadcast,
            active: false,
            badge: 0,
            isAction: true,
        });
    }

    return entries;
}

/**
 * One entry in the rail: an icon, with the name in a tooltip.
 *
 * That is a real trade — an icon is slower to learn than a word — and it is
 * worth it because there are a handful of them, they never change, and the
 * badge is what people are actually scanning for. Below lg the trade stops
 * paying: there is no tooltip on a touchscreen, so the menu spells them out.
 */
function RailButton({ entry }: { entry: ToolEntry }) {
    const className = cn(
        'relative flex size-10 items-center justify-center rounded-md transition-colors',
        entry.active
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
    );

    const Icon = entry.icon;

    const body = (
        <>
            <Icon className="size-[18px]" />
            {entry.badge > 0 && (
                <span className="absolute top-1 right-1 min-w-4 rounded-full bg-red-500 px-1 text-[10px] leading-4 font-semibold text-white">
                    {entry.badge}
                </span>
            )}
            <span className="sr-only">{entry.label}</span>
        </>
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {entry.href ? (
                    <Link href={entry.href} className={className}>
                        {body}
                    </Link>
                ) : (
                    <button
                        type="button"
                        onClick={entry.onClick}
                        className={className}
                    >
                        {body}
                    </button>
                )}
            </TooltipTrigger>
            {/* To the side: above would cover the rail's own neighbour. */}
            <TooltipContent side="right">{entry.label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The same entries by name, for the menu that opens on a narrow screen.
 *
 * Rows rather than a grid of icons: the menu is already a list of channels, and
 * an entry here reads as one more place to go — which is exactly what it is.
 */
export function WorkspaceToolLinks(props: WorkspaceToolsProps) {
    const { t } = useTranslate();

    const entries = toolEntries(props, t);
    const places = entries.filter((entry) => !entry.isAction);
    const actions = entries.filter((entry) => entry.isAction);

    const row = (entry: ToolEntry) => {
        const Icon = entry.icon;

        const className = cn(
            'flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
            entry.active
                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
        );

        const body = (
            <>
                <Icon className="size-4 shrink-0 opacity-70" />
                <span className="truncate">{entry.label}</span>
                {entry.badge > 0 && (
                    <span className="ml-auto min-w-4 rounded-full bg-red-500 px-1 text-center text-[10px] leading-4 font-semibold text-white">
                        {entry.badge}
                    </span>
                )}
            </>
        );

        return entry.href ? (
            <Link key={entry.key} href={entry.href} className={className}>
                {body}
            </Link>
        ) : (
            <button
                key={entry.key}
                type="button"
                onClick={entry.onClick}
                className={cn(className, 'w-full text-left')}
            >
                {body}
            </button>
        );
    };

    return (
        <nav
            aria-label={t('sidebar.toolbar')}
            className="space-y-0.5 border-b border-sidebar-border px-2 pb-2"
        >
            {places.map(row)}
            {actions.length > 0 && (
                <div className="mt-1 border-t border-sidebar-border pt-1">
                    {actions.map(row)}
                </div>
            )}
        </nav>
    );
}

/**
 * The rail, optionally standing on its own.
 *
 * Header and footer are empty while the channel column is beside it, because
 * the column already carries the workspace's name at its top and the reader's
 * own face at its bottom. On a screen where the column is gone — every screen
 * that is not the chat — the rail is the only furniture left, and those two
 * would go with it. See ChannelSidebar, which decides which of the two is on
 * screen and hands the replacements down.
 */
export function WorkspaceRail({
    header,
    footer,
    ...props
}: WorkspaceToolsProps & { header?: ReactNode; footer?: ReactNode }) {
    const { t } = useTranslate();

    const entries = toolEntries(props, t);
    const places = entries.filter((entry) => !entry.isAction);
    const actions = entries.filter((entry) => entry.isAction);

    return (
        <nav
            aria-label={t('sidebar.toolbar')}
            /*
                Above lg only. There is no version of this column that earns
                3.5rem of a phone's width: it would be holding one hamburger,
                and the menu behind that hamburger already carries every entry
                by name. See ChannelMenuButton, which is where the way in went.
            */
            className="hidden h-full w-14 shrink-0 flex-col items-center gap-1 border-r border-sidebar-border bg-sidebar py-2 lg:flex"
        >
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

            {header && <div className="mb-1">{header}</div>}

            {/*
                min-h-0 lets this flex child actually shrink instead of
                growing past the nav's fixed height: without it, a workspace
                with enough tools pushes the footer below the nav's box
                entirely rather than scrolling within it — taking the user
                menu's trigger out of the viewport along with it.
            */}
            <div className="flex min-h-0 flex-1 flex-col items-center gap-1 overflow-y-auto">
                {places.map((entry) => (
                    <RailButton key={entry.key} entry={entry} />
                ))}

                {/*
                    Pushed to the bottom: the ones above are places you go, this
                    is a thing you do. Grouping by that rather than by how often
                    it is used keeps the rail readable as it grows.
                */}
                {actions.length > 0 && (
                    <div className="mt-auto flex flex-col items-center gap-1">
                        {actions.map((entry) => (
                            <RailButton key={entry.key} entry={entry} />
                        ))}
                    </div>
                )}
            </div>

            {footer && (
                <div className="mt-1 w-full border-t border-sidebar-border px-2 pt-2">
                    {footer}
                </div>
            )}
        </nav>
    );
}
