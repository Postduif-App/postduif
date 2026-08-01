import { Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    AtSign,
    BellOff,
    Bookmark,
    ChevronDown,
    Hash,
    Lock,
    Megaphone,
    MessageSquare,
    Plus,
    Search,
    Settings,
    Ticket as TicketIcon,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';

import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useCollapsedSection } from '@/hooks/use-collapsed-section';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { archive as archiveChannel } from '@/routes/chat/channels';
import { destroy as destroyDirect } from '@/routes/chat/directs';
import { index as mentionsIndex } from '@/routes/chat/mentions';
import { index as savedIndex } from '@/routes/chat/saved';
import { close as closeThread } from '@/routes/chat/threads';
import { index as ticketsIndex } from '@/routes/chat/tickets';
import { edit as workspaceSettings } from '@/routes/workspace';
import { index as workspaceMembers } from '@/routes/workspace/members';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
} from '@/types/chat';

interface ChannelSidebarProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    /** Threads with recent activity, across every channel this member sees. */
    activeThreads: ActiveThread[];
    /** The channel being read, or null on a page that is not one — the
     *  workspace-wide ticket list. */
    activeChannelId: number | null;
    onOpenSearch: () => void;
    /** Opens the dialog that sends one message into several channels. */
    onBroadcast?: () => void;
    /** True on the workspace-wide ticket page, so its row reads as current. */
    ticketsActive?: boolean;
    /** Marks the mentions row, the same way ticketsActive marks its own. */
    mentionsActive?: boolean;
    /** Marks the saved row. */
    savedActive?: boolean;
    /**
     * Channels that were put away, for whoever may take them back out. Empty
     * for everybody else, so the section simply does not appear.
     */
    archivedChannels?: ArchivedChannel[];
    /** The groups this member arranged for themselves. */
    sections?: ChannelSectionRow[];
    /** Every tag on a channel this member can see, for the filter above the list. */
    workspaceTags?: string[];
    onCreateChannel: () => void;
    onStartDirectMessage: () => void;
    onInvitePeople: () => void;
    /** The signed-in member's own menu, at the foot of the sidebar. */
    userMenu: ReactNode;
}

function ChannelIcon({
    type,
    className,
}: {
    type: ChannelSummary['type'];
    className?: string;
}) {
    if (type === 'private') {
        return <Lock className={className} />;
    }

    if (type === 'dm') {
        return <MessageSquare className={className} />;
    }

    return <Hash className={className} />;
}

function ChannelLink({
    workspaceSlug,
    channel,
    active,
}: {
    workspaceSlug: string;
    channel: ChannelSummary;
    active: boolean;
}) {
    return (
        <Link
            href={show({ workspace: workspaceSlug, channel: channel.id })}
            // No prefetch: opening a channel marks it read, and prefetching
            // fires that same request on hover — so merely sweeping the mouse
            // down the sidebar would clear every badge.
            className={cn(
                // min-w-0 so a long channel name truncates rather than pushing
                // the unread badge out of the sidebar.
                'group flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
                active
                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
            )}
        >
            {/*
                For a one-on-one the availability dot replaces the icon: the row
                stands for a person, and "this is a DM" is already obvious from
                the section it sits in.
            */}
            {channel.status?.availability &&
            channel.status.availability !== 'available' ? (
                <span className="flex size-4 shrink-0 items-center justify-center">
                    <AvailabilityDot
                        availability={channel.status.availability}
                    />
                </span>
            ) : (
                <ChannelIcon
                    type={channel.type}
                    className="size-4 shrink-0 opacity-70"
                />
            )}
            <span
                className={cn(
                    'truncate',
                    // Unread reads as weight, not as a second badge: the eye
                    // finds a bold row faster than it counts numbers.
                    channel.unreadCount > 0 &&
                        !active &&
                        'font-semibold text-sidebar-foreground',
                    // A muted channel steps back rather than disappearing: the
                    // member asked for quiet, not for it to be hidden.
                    channel.mutedUntil !== null && 'opacity-60',
                )}
            >
                {channel.label}
            </span>

            {channel.mutedUntil !== null && (
                <BellOff
                    className="size-3 shrink-0 text-muted-foreground"
                    aria-label="Meldingen staan uit"
                />
            )}

            {channel.status && (
                <MemberStatus
                    emoji={channel.status.emoji}
                    text={channel.status.text}
                    className="shrink-0"
                />
            )}

            {/*
                One trailing group rather than badges that each claim the right
                edge: with two of them, whichever came first would push the other
                out of place the moment it disappeared.
            */}
            <span className="ml-auto flex shrink-0 items-center gap-1.5">
                {/*
                    Outstanding tickets stay quiet next to the unread badge: a
                    ticket is not a message waiting to be read, and drawing it in
                    the same red would make every customer channel shout.
                */}
                {channel.openTicketCount > 0 && (
                    <span
                        title={`${channel.openTicketCount} openstaande tickets`}
                        className="flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                    >
                        <TicketIcon className="size-2.5" />
                        {channel.openTicketCount}
                    </span>
                )}

                {channel.mentionCount > 0 ? (
                    <span className="rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        {channel.mentionCount}
                    </span>
                ) : (
                    channel.unreadCount > 0 &&
                    !active && (
                        <span className="size-1.5 rounded-full bg-sidebar-foreground/60" />
                    )
                )}
            </span>
        </Link>
    );
}

/**
 * The workspace name, and everything you can do to the workspace as a whole.
 *
 * A plain heading for anybody who runs nothing: a menu with a single disabled
 * item is worse than no menu, and the chevron would promise something.
 */
function WorkspaceMenu({
    workspace,
    onInvite,
}: {
    workspace: ChatWorkspace;
    onInvite: () => void;
}) {
    /*
        The logo when there is one, the first two letters otherwise. Same square
        either way, so the row does not shift the moment somebody uploads one.
    */
    const badge = workspace.avatarUrl ? (
        <img
            src={workspace.avatarUrl}
            alt=""
            className="size-7 shrink-0 rounded-md object-cover"
        />
    ) : (
        <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-xs font-bold text-primary-foreground">
            {workspace.name.slice(0, 2).toUpperCase()}
        </div>
    );

    if (!workspace.canInvite && !workspace.canManage) {
        return (
            <div className="flex items-center gap-2 px-2">
                {badge}
                <span className="truncate font-semibold">{workspace.name}</span>
            </div>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:outline-none">
                {badge}
                <span className="truncate font-semibold">{workspace.name}</span>
                <ChevronDown className="ml-auto size-4 shrink-0 opacity-60" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
                {workspace.canInvite && (
                    <DropdownMenuItem onSelect={onInvite}>
                        <UserPlus className="mr-2 size-4" />
                        Mensen uitnodigen
                    </DropdownMenuItem>
                )}
                {workspace.canManage && (
                    <>
                        <DropdownMenuItem asChild>
                            <Link href={workspaceMembers()}>
                                <Users className="mr-2 size-4" />
                                Leden
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={workspaceSettings()}>
                                <Settings className="mr-2 size-4" />
                                Workspace-instellingen
                            </Link>
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="px-2 pt-4 pb-1 text-xs font-semibold tracking-wide text-sidebar-foreground/50 uppercase">
            {children}
        </h2>
    );
}

/**
 * One recently active thread, indented under its channel.
 *
 * The row is a link into the thread panel — the same URL the "N antwoorden"
 * link in the channel uses, so a thread opened from here is the same thing you
 * would have reached the long way round. Closing asks first: it is the sort of
 * button you hit while aiming for the row behind it, and a thread that vanished
 * on a misclick would leave you hunting for it in the channel.
 */
function ThreadRow({
    workspaceSlug,
    thread,
}: {
    workspaceSlug: string;
    thread: ActiveThread;
}) {
    const [confirming, setConfirming] = useState(false);

    const close = () => {
        router.post(
            closeThread({
                workspace: workspaceSlug,
                channel: thread.channelId,
                message: thread.id,
            }),
            {},
            // The member is reading a conversation, so the page itself must not
            // move. The channel lists ride along with the thread list because
            // useSidebarActivity drops its unread deltas whenever a visit
            // finishes — without fresh counts in the response, closing a thread
            // would clear other channels' badges.
            {
                preserveScroll: true,
                preserveState: true,
                only: ['activeThreads', 'channels', 'directMessages'],
            },
        );
    };

    return (
        <div className="group flex items-start gap-1">
            <Link
                href={show(
                    { workspace: workspaceSlug, channel: thread.channelId },
                    { query: { thread: thread.id } },
                )}
                className="flex min-w-0 flex-1 items-start gap-2 rounded-md px-2 py-1 text-sm text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
            >
                <MessageSquare className="mt-0.5 size-3.5 shrink-0 opacity-50" />

                <span className="min-w-0 flex-1">
                    <span className="line-clamp-1 text-xs">
                        {thread.snippet === '' ? (
                            <span className="italic opacity-60">
                                Bericht verwijderd
                            </span>
                        ) : (
                            <>
                                <span className="font-medium">
                                    {thread.author}
                                </span>
                                {': '}
                                {thread.snippet}
                            </>
                        )}
                    </span>
                    <span className="text-[10px] text-sidebar-foreground/40">
                        {thread.replyCount}{' '}
                        {thread.replyCount === 1 ? 'antwoord' : 'antwoorden'}
                    </span>
                </span>
            </Link>

            <button
                type="button"
                onClick={() => setConfirming(true)}
                // Shown on hover and on keyboard focus: hiding it behind the
                // pointer alone would put it out of reach without a mouse.
                className="mt-1 shrink-0 rounded p-0.5 opacity-0 transition-opacity group-hover:opacity-60 hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                aria-label="Thread sluiten"
                title="Thread sluiten"
            >
                <X className="size-3.5" />
            </button>

            {confirming && (
                <AlertDialog open onOpenChange={setConfirming}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Deze thread sluiten?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                De thread verdwijnt uit jouw zijbalk; voor de
                                anderen blijft hij staan. Zodra er weer iets
                                gezegd wordt, komt hij terug.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Annuleren</AlertDialogCancel>
                            <AlertDialogAction onClick={close}>
                                Sluiten
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </div>
    );
}

/**
 * A channel, with its own recently active threads hanging underneath it.
 *
 * Threads live here rather than in a section of their own: a thread belongs to
 * the conversation it came from, and a separate list would name every channel
 * twice. The chevron folds a channel's threads away — it appears only for a
 * channel that has any, so the sidebar never shows a control that does nothing.
 */
function ChannelRow({
    workspaceSlug,
    channel,
    active,
    threads,
}: {
    workspaceSlug: string;
    channel: ChannelSummary;
    active: boolean;
    threads: ActiveThread[];
}) {
    const [collapsed, toggle] = useCollapsedSection(`threads.${channel.id}`);
    const hasThreads = threads.length > 0;

    return (
        <div>
            <div className="group/row flex items-center">
                {hasThreads ? (
                    <button
                        type="button"
                        onClick={toggle}
                        aria-expanded={!collapsed}
                        aria-label={`Threads in ${channel.label} ${collapsed ? 'tonen' : 'verbergen'}`}
                        className="shrink-0 rounded p-0.5 text-sidebar-foreground/40 transition-colors hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <ChevronDown
                            className={cn(
                                'size-3 transition-transform',
                                collapsed && '-rotate-90',
                            )}
                        />
                    </button>
                ) : (
                    // Keeps every channel name on the same left edge, whether
                    // or not it has a chevron in front of it.
                    <span className="size-4 shrink-0" />
                )}

                <ChannelLink
                    workspaceSlug={workspaceSlug}
                    channel={channel}
                    active={active}
                />

                {/*
                    Only on a one-on-one. A channel you no longer want in your
                    sidebar is one you leave, which has its own button and a
                    different meaning: leaving is something the other members
                    can see, this is not.
                */}
                {channel.type === 'dm' && (
                    <button
                        type="button"
                        title="Uit je zijbalk halen. De ander merkt er niets van, en een nieuw bericht brengt het gesprek terug."
                        aria-label={`Gesprek met ${channel.label} uit je zijbalk halen`}
                        onClick={() =>
                            router.delete(
                                destroyDirect.url({
                                    workspace: workspaceSlug,
                                    channel: channel.id,
                                }),
                                { preserveScroll: true },
                            )
                        }
                        className="mr-1 shrink-0 rounded p-1 text-sidebar-foreground/40 opacity-0 transition-opacity group-hover/row:opacity-100 hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <X className="size-3" />
                    </button>
                )}
            </div>

            {hasThreads && !collapsed && (
                <div className="mt-0.5 ml-5 space-y-0.5 border-l border-sidebar-border pl-1">
                    {threads.map((thread) => (
                        <ThreadRow
                            key={thread.id}
                            workspaceSlug={workspaceSlug}
                            thread={thread}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * The threads of each channel, keyed by channel id.
 *
 * Every thread the server sends belongs to a channel this member can see, so
 * each one finds a row to sit under.
 */
function groupByChannel(threads: ActiveThread[]): Map<number, ActiveThread[]> {
    const grouped = new Map<number, ActiveThread[]>();

    for (const thread of threads) {
        const existing = grouped.get(thread.channelId);

        if (existing) {
            existing.push(thread);
        } else {
            grouped.set(thread.channelId, [thread]);
        }
    }

    return grouped;
}

export function ChannelSidebar({
    workspace,
    channels,
    directMessages,
    activeThreads,
    activeChannelId,
    onOpenSearch,
    onBroadcast,
    ticketsActive = false,
    mentionsActive = false,
    savedActive = false,
    archivedChannels = [],
    sections = [],
    workspaceTags = [],
    onCreateChannel,
    onStartDirectMessage,
    onInvitePeople,
    userMenu,
}: ChannelSidebarProps) {
    const threadsByChannel = useMemo(
        () => groupByChannel(activeThreads),
        [activeThreads],
    );

    // Summed over the same rows the badges are drawn from, so the number beside
    // "Vermeldingen" can never disagree with the ones in the list below it.
    const mentionTotal = [...channels, ...directMessages].reduce(
        (total, row) => total + row.mentionCount,
        0,
    );

    /**
     * The tag the channel list is narrowed to, or null.
     *
     * Component state rather than the URL, unlike a thread or a ticket: this is
     * how somebody is looking at their own sidebar for a moment, not a view of
     * the workspace worth linking to. It also has to survive navigating between
     * channels, which is why it lives here and not in the page.
     */
    const [tagFilter, setTagFilter] = useState<string | null>(null);

    // A tag that no longer sits on anything visible is not a filter — it is a
    // dead end. Cheaper to drop it here than to explain an empty list.
    const filterable = workspaceTags.filter((tag) =>
        channels.some((channel) => channel.tags?.includes(tag)),
    );

    const shown =
        tagFilter === null
            ? channels
            : channels.filter((channel) => channel.tags?.includes(tagFilter));

    /*
     * Favourites in their own group above the rest, rather than sorted to the
     * top of one list: a list that silently reorders itself is one people stop
     * being able to point at, where a heading says what happened.
     *
     * The tag filter applies to both, so filtering never hides something that
     * was starred.
     */
    const favorites = shown.filter((channel) => channel.isFavorite);

    /*
     * A channel appears once. Favourites win over a section — somebody who
     * starred it asked for it at the top — and everything left over falls into
     * the ordinary list.
     */
    const filed = new Set(sections.flatMap((section) => section.channelIds));
    const inSection = (id: number) => filed.has(id);

    const ordinary = shown.filter(
        (channel) => !channel.isFavorite && !inSection(channel.id),
    );

    return (
        <aside className="flex h-full w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
            <div className="flex h-14 items-center border-b border-sidebar-border px-2">
                <WorkspaceMenu
                    workspace={workspace}
                    onInvite={onInvitePeople}
                />
            </div>

            <div className="p-2">
                <Button
                    variant="outline"
                    size="sm"
                    className="w-full justify-start gap-2 font-normal text-muted-foreground"
                    onClick={onOpenSearch}
                >
                    <Search className="size-4" />
                    Zoeken
                    <kbd className="ml-auto rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                        ⌘K
                    </kbd>
                </Button>

                {/*
                    Under the search box because it is the same kind of thing:
                    something you do to the workspace rather than to the channel
                    you happen to have open.
                */}
                {onBroadcast && workspace.canBroadcastToChannels && (
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Eén bericht naar meerdere kanalen"
                        className="mt-1 w-full justify-start gap-2 font-normal text-muted-foreground"
                        onClick={onBroadcast}
                    >
                        <Megaphone className="size-4 shrink-0" />
                        {/*
                            Short enough for the sidebar, which is 16rem wide
                            and does not grow. What it actually does is in the
                            tooltip and in the dialog's own title; a label that
                            spells it out here only runs past the edge.
                        */}
                        <span className="truncate">Rondsturen</span>
                    </Button>
                )}
            </div>

            <ScrollArea className="flex-1 px-2 pb-4">
                {/*
                    Only once some channel actually keeps tickets. Before that
                    the page has nothing to show, and a permanent row leading to
                    an empty list is a row people learn to ignore.
                */}
                {/*
                    Always there, unlike the ticket row above: being named is
                    something that happens to everybody, and an empty list still
                    answers the question it was opened with.
                */}
                <Link
                    href={mentionsIndex(workspace.slug)}
                    className={cn(
                        'mb-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
                        mentionsActive
                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                    )}
                >
                    <AtSign className="size-4 shrink-0 opacity-70" />
                    Vermeldingen
                    {mentionTotal > 0 && (
                        <span className="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                            {mentionTotal}
                        </span>
                    )}
                </Link>

                <Link
                    href={savedIndex(workspace.slug)}
                    className={cn(
                        'mb-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
                        savedActive
                            ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                    )}
                >
                    <Bookmark className="size-4 shrink-0 opacity-70" />
                    Bewaard
                </Link>

                {channels.some((row) => row.hasTickets) && (
                    <Link
                        href={ticketsIndex(workspace.slug)}
                        className={cn(
                            'mb-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors',
                            ticketsActive
                                ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'
                                : 'text-sidebar-foreground/70 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                        )}
                    >
                        <TicketIcon className="size-4 shrink-0 opacity-70" />
                        Tickets
                    </Link>
                )}

                {filterable.length > 0 && (
                    <div className="mb-2 flex flex-wrap gap-1">
                        {filterable.map((tag) => {
                            const selected = tagFilter === tag;

                            return (
                                <button
                                    key={tag}
                                    type="button"
                                    aria-pressed={selected}
                                    // Clicking the tag you are already on takes
                                    // the filter off: the way back is the same
                                    // gesture as the way in.
                                    onClick={() =>
                                        setTagFilter(selected ? null : tag)
                                    }
                                    className={cn(
                                        'rounded-full border px-2 py-0.5 text-[11px] font-medium transition-colors',
                                        selected
                                            ? 'border-primary/50 bg-primary/10 text-sidebar-foreground'
                                            : 'text-sidebar-foreground/60 hover:bg-sidebar-accent/50 hover:text-sidebar-foreground',
                                    )}
                                >
                                    {tag}
                                </button>
                            );
                        })}
                    </div>
                )}

                {favorites.length > 0 && (
                    <>
                        <SectionHeading>Favorieten</SectionHeading>
                        <div className="mb-2 space-y-0.5">
                            {favorites.map((channel) => (
                                <ChannelRow
                                    key={channel.id}
                                    workspaceSlug={workspace.slug}
                                    channel={channel}
                                    active={channel.id === activeChannelId}
                                    threads={
                                        threadsByChannel.get(channel.id) ?? []
                                    }
                                />
                            ))}
                        </div>
                    </>
                )}

                {sections.map((section) => {
                    const rows = shown.filter(
                        (channel) =>
                            !channel.isFavorite &&
                            section.channelIds.includes(channel.id),
                    );

                    return (
                        <div key={section.id} className="mb-2">
                            <SectionHeading>{section.name}</SectionHeading>
                            <div className="space-y-0.5">
                                {rows.map((channel) => (
                                    <ChannelRow
                                        key={channel.id}
                                        workspaceSlug={workspace.slug}
                                        channel={channel}
                                        active={channel.id === activeChannelId}
                                        threads={
                                            threadsByChannel.get(channel.id) ??
                                            []
                                        }
                                    />
                                ))}
                                {/*
                                    A group somebody just made has nothing in it
                                    yet, and an empty heading with no
                                    explanation reads as a bug.
                                */}
                                {rows.length === 0 && (
                                    <p className="px-2 py-1 text-xs text-sidebar-foreground/50">
                                        Nog geen kanalen in deze groep.
                                    </p>
                                )}
                            </div>
                        </div>
                    );
                })}

                <SectionHeading>Kanalen</SectionHeading>
                <div className="space-y-0.5">
                    {ordinary.map((channel) => (
                        <ChannelRow
                            key={channel.id}
                            workspaceSlug={workspace.slug}
                            channel={channel}
                            active={channel.id === activeChannelId}
                            threads={threadsByChannel.get(channel.id) ?? []}
                        />
                    ))}
                    {tagFilter !== null && shown.length === 0 && (
                        <p className="px-2 py-1 text-sm text-muted-foreground">
                            Geen kanalen met deze tag.
                        </p>
                    )}
                    {channels.length === 0 && !workspace.canCreateChannel && (
                        <p className="px-2 py-1 text-sm text-muted-foreground">
                            Geen kanalen
                        </p>
                    )}
                    {workspace.canCreateChannel && (
                        <button
                            type="button"
                            onClick={onCreateChannel}
                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
                        >
                            <Plus className="size-4" />
                            {/*
                                Doubles as the empty state, like "Start een
                                gesprek" below: "Geen kanalen" only names the
                                problem, this row is the way out of it.
                            */}
                            {channels.length === 0
                                ? 'Eerste kanaal maken'
                                : 'Kanaal toevoegen'}
                        </button>
                    )}
                </div>

                <SectionHeading>Directe berichten</SectionHeading>
                <div className="space-y-0.5">
                    {directMessages.map((channel) => (
                        <ChannelRow
                            key={channel.id}
                            workspaceSlug={workspace.slug}
                            channel={channel}
                            active={channel.id === activeChannelId}
                            threads={threadsByChannel.get(channel.id) ?? []}
                        />
                    ))}
                    {directMessages.length === 0 &&
                        !workspace.canStartDirectMessage && (
                            <p className="px-2 py-1 text-sm text-muted-foreground">
                                Nog geen gesprekken
                            </p>
                        )}
                    {workspace.canStartDirectMessage && (
                        <button
                            type="button"
                            onClick={onStartDirectMessage}
                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent/50 hover:text-sidebar-foreground"
                        >
                            <Plus className="size-4" />
                            {/*
                                Doubles as the empty state: with no
                                conversations yet, "Nog geen gesprekken" only
                                states the problem, and this row is the way out
                                of it.
                            */}
                            {directMessages.length === 0
                                ? 'Start een gesprek'
                                : 'Nieuw gesprek'}
                        </button>
                    )}
                </div>

                {/*
                    Last in the list, and only for whoever may take one back
                    out. An archived channel is meant to be out of the way, so
                    it sits below everything live rather than among it.
                */}
                {archivedChannels.length > 0 && (
                    <div className="mt-4">
                        <p className="px-2 pb-1 text-xs font-medium text-sidebar-foreground/50">
                            Gearchiveerd
                        </p>
                        {archivedChannels.map((channel) => (
                            <div
                                key={channel.id}
                                className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-sidebar-foreground/50"
                            >
                                <Archive className="size-4 shrink-0 opacity-70" />
                                <span className="truncate">
                                    {channel.label}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            archiveChannel.url({
                                                workspace: workspace.slug,
                                                channel: channel.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                    title={`${channel.label} heropenen`}
                                    aria-label={`${channel.label} heropenen`}
                                    className="ml-auto shrink-0 rounded p-1 hover:bg-sidebar-accent hover:text-sidebar-foreground"
                                >
                                    <ArchiveRestore className="size-3.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </ScrollArea>
            <div className="border-t border-sidebar-border p-1.5">
                {userMenu}
            </div>
        </aside>
    );
}
