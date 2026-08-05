import { Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BellOff,
    ChevronDown,
    Hash,
    Lock,
    MessageSquare,
    Pencil,
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
import { WorkspaceRail } from '@/components/chat/workspace-rail';
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
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useCollapsedSection } from '@/hooks/use-collapsed-section';
import { useInboxActivity } from '@/hooks/use-inbox-activity';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as chatIndex, show } from '@/routes/chat';
import { archive as archiveChannel } from '@/routes/chat/channels';
import { destroy as destroyDirect } from '@/routes/chat/directs';
import { rename as renameSection } from '@/routes/chat/sections';
import {
    close as closeThread,
    mute as muteThread,
    unmute as unmuteThread,
} from '@/routes/chat/threads';
import { edit as workspaceSettings } from '@/routes/workspace';
import { index as workspaceMembers } from '@/routes/workspace/members';
import type { Auth } from '@/types';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    WorkspaceOption,
} from '@/types/chat';

interface ChannelSidebarProps {
    workspace: ChatWorkspace;
    /** Every workspace this member belongs to, for the switcher up top. */
    workspaces: WorkspaceOption[];
    /** Unread inbox rows of every kind, as the server last counted them. */
    inboxUnread: number;
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
    /** True on the prikbord, so its rail entry reads as current. */
    boardActive?: boolean;
    /** True on the secrets page, so its rail entry reads as current. */
    secretsActive?: boolean;
    /** True on any of the three form screens, for the same reason. */
    formsActive?: boolean;
    /** True on the workspace-wide ticket page, so its row reads as current. */
    ticketsActive?: boolean;
    /** Marks the mentions row, the same way ticketsActive marks its own. */
    mentionsActive?: boolean;
    /** Marks the saved row. */
    savedActive?: boolean;
    transfersActive?: boolean;
    timeclockActive?: boolean;
    /**
     * Channels that were put away, for whoever may take them back out. Empty
     * for everybody else, so the section simply does not appear.
     */
    archivedChannels?: ArchivedChannel[];
    /** The groups this member arranged for themselves. */
    sections?: ChannelSectionRow[];
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
    const { t, tChoice } = useTranslate();

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
                    aria-label={t('sidebar.channel.muted')}
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
                        title={tChoice(
                            'sidebar.channel.open_tickets',
                            channel.openTicketCount,
                        )}
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
 * The workspace name, everything you can do to the workspace as a whole, and
 * the way across to another one.
 *
 * A plain heading only for somebody who runs nothing and belongs to nothing
 * else: a menu with a single disabled item is worse than no menu, and the
 * chevron would promise something. Belonging to a second workspace is enough on
 * its own, though — switching is the one thing in here that needs no
 * permission.
 */
function WorkspaceMenu({
    workspace,
    workspaces,
    onInvite,
}: {
    workspace: ChatWorkspace;
    workspaces: WorkspaceOption[];
    onInvite: () => void;
}) {
    const { t } = useTranslate();

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

    const elsewhere = workspaces.filter((option) => !option.isCurrent);

    if (
        !workspace.canInvite &&
        !workspace.canManage &&
        elsewhere.length === 0
    ) {
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
                        {t('sidebar.workspace.invite')}
                    </DropdownMenuItem>
                )}
                {workspace.canManage && (
                    <>
                        <DropdownMenuItem asChild>
                            <Link href={workspaceMembers()}>
                                <Users className="mr-2 size-4" />
                                {t('sidebar.workspace.members')}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href={workspaceSettings()}>
                                <Settings className="mr-2 size-4" />
                                {t('sidebar.workspace.settings')}
                            </Link>
                        </DropdownMenuItem>
                    </>
                )}

                {/*
                    Below the rest and behind a separator, because it leaves
                    rather than does something here. The current workspace is
                    left out of the list — it is already the name on the button
                    above, and a row that lands you where you are is a row that
                    reads as broken.
                */}
                {elsewhere.length > 0 && (
                    <>
                        {(workspace.canInvite || workspace.canManage) && (
                            <DropdownMenuSeparator />
                        )}
                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                            {t('sidebar.workspace.switch')}
                        </DropdownMenuLabel>
                        {elsewhere.map((option) => (
                            <DropdownMenuItem key={option.id} asChild>
                                <Link href={chatIndex(option.slug)}>
                                    {option.avatarUrl ? (
                                        <img
                                            src={option.avatarUrl}
                                            alt=""
                                            className="mr-2 size-4 rounded object-cover"
                                        />
                                    ) : (
                                        <span className="mr-2 flex size-4 items-center justify-center rounded bg-primary text-[9px] font-bold text-primary-foreground">
                                            {option.name
                                                .slice(0, 1)
                                                .toUpperCase()}
                                        </span>
                                    )}
                                    <span className="truncate">
                                        {option.name}
                                    </span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
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
 * A group's heading, renamable in place.
 *
 * In place rather than in a dialog: the name is one short line and it is
 * already on screen, so a modal to change it would be more chrome than
 * content. Enter saves and Escape cancels, the same pair MessageEditor uses —
 * two fields in one screen that answer the same key differently is the kind of
 * thing you only notice by losing something.
 */
function SectionHeadingRow({
    workspaceSlug,
    section,
}: {
    workspaceSlug: string;
    section: ChannelSectionRow;
}) {
    const { t } = useTranslate();

    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(section.name);

    const save = () => {
        const trimmed = name.trim();

        // Nothing to send when it did not change, and an empty name would be
        // refused by the endpoint anyway — better to simply step back out.
        if (trimmed === '' || trimmed === section.name) {
            setName(section.name);
            setEditing(false);

            return;
        }

        router.patch(
            renameSection({ workspace: workspaceSlug, section: section.id }),
            { name: trimmed },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['sections'],
                onError: () => setName(section.name),
            },
        );

        setEditing(false);
    };

    if (editing) {
        return (
            <div className="min-w-0 px-2 pt-4 pb-1">
                <input
                    autoFocus
                    value={name}
                    maxLength={40}
                    /*
                        size={1} rather than the browser's default of 20. The
                        sidebar scrolls inside a Radix viewport, whose inner
                        element is display:table — so its width follows its
                        content, and an input's intrinsic width is content. With
                        the default the field pushed the whole column wider than
                        the panel; at 1 there is nothing to push with and w-full
                        resolves against the panel, as intended.
                    */
                    size={1}
                    onChange={(event) => setName(event.target.value)}
                    onBlur={save}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            save();
                        }

                        if (event.key === 'Escape') {
                            event.preventDefault();
                            setName(section.name);
                            setEditing(false);
                        }
                    }}
                    aria-label={t('sidebar.section.name_field')}
                    className="w-full min-w-0 rounded border bg-background px-1.5 py-0.5 text-xs font-semibold tracking-wide uppercase focus-visible:ring-2 focus-visible:outline-none"
                />
            </div>
        );
    }

    return (
        <div className="group/section flex items-center gap-1 pr-2">
            <SectionHeading>{section.name}</SectionHeading>
            <button
                type="button"
                onClick={() => setEditing(true)}
                // Shown on hover and on keyboard focus, like the thread row's
                // close button: hiding it behind the pointer alone would put it
                // out of reach without a mouse.
                className="mt-3 shrink-0 rounded p-0.5 opacity-0 transition-opacity group-hover/section:opacity-60 hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                aria-label={t('sidebar.section.rename_named', {
                    name: section.name,
                })}
                title={t('sidebar.section.rename')}
            >
                <Pencil className="size-3" />
            </button>
        </div>
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
    const { t, tChoice } = useTranslate();

    const [confirming, setConfirming] = useState(false);

    /*
     * Both actions send the same visit options for the same reason. The member
     * is reading a conversation, so the page must not move; the channel lists
     * ride along because useSidebarActivity drops its unread deltas whenever a
     * visit finishes, and a response without fresh counts would clear other
     * channels' badges.
     */
    const options = {
        preserveScroll: true,
        preserveState: true,
        only: ['activeThreads', 'channels', 'directMessages'],
    };

    const toggleMute = () => {
        const route = {
            workspace: workspaceSlug,
            channel: thread.channelId,
            message: thread.id,
        };

        if (thread.muted) {
            router.delete(unmuteThread(route), options);
        } else {
            router.post(muteThread(route), {}, options);
        }

        setConfirming(false);
    };

    const close = () => {
        router.post(
            closeThread({
                workspace: workspaceSlug,
                channel: thread.channelId,
                message: thread.id,
            }),
            {},
            options,
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
                                {t('sidebar.thread.deleted')}
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
                        {tChoice('sidebar.thread.replies', thread.replyCount)}
                    </span>
                </span>
            </Link>

            <button
                type="button"
                onClick={() => setConfirming(true)}
                // Shown on hover and on keyboard focus: hiding it behind the
                // pointer alone would put it out of reach without a mouse.
                className="mt-1 shrink-0 rounded p-0.5 opacity-0 transition-opacity group-hover:opacity-60 hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:outline-none"
                aria-label={t('sidebar.thread.menu')}
                title={t('sidebar.thread.menu')}
            >
                <X className="size-3.5" />
            </button>

            {confirming && (
                <AlertDialog open onOpenChange={setConfirming}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {t('sidebar.thread.question')}
                            </AlertDialogTitle>
                            {/*
                                Both choices spelled out, because the difference
                                between them is the whole reason there are two
                                and it is not guessable from the words alone.
                            */}
                            <AlertDialogDescription>
                                <strong>{t('sidebar.thread.close')}</strong>{' '}
                                {t('sidebar.thread.explain_close')}
                                {thread.muted ? (
                                    <>
                                        {' '}
                                        <strong>
                                            {t('sidebar.thread.unmute')}
                                        </strong>{' '}
                                        {t('sidebar.thread.explain_unmute')}
                                    </>
                                ) : (
                                    <>
                                        {' '}
                                        <strong>
                                            {t('sidebar.thread.mute')}
                                        </strong>{' '}
                                        {t('sidebar.thread.explain_mute')}
                                    </>
                                )}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>
                                {t('sidebar.thread.cancel')}
                            </AlertDialogCancel>
                            <Button variant="outline" onClick={toggleMute}>
                                {thread.muted
                                    ? t('sidebar.thread.unmute')
                                    : t('sidebar.thread.mute')}
                            </Button>
                            <AlertDialogAction onClick={close}>
                                {t('sidebar.thread.close')}
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
    const { t } = useTranslate();

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
                        aria-label={t(
                            collapsed
                                ? 'sidebar.channel.threads_show'
                                : 'sidebar.channel.threads_hide',
                            { channel: channel.label },
                        )}
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
                        title={t('sidebar.channel.hide_direct_hint')}
                        aria-label={t('sidebar.channel.hide_direct', {
                            channel: channel.label,
                        })}
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
    workspaces,
    inboxUnread,
    channels,
    directMessages,
    activeThreads,
    activeChannelId,
    onOpenSearch,
    onBroadcast,
    boardActive = false,
    secretsActive = false,
    formsActive = false,
    ticketsActive = false,
    mentionsActive = false,
    savedActive = false,
    transfersActive = false,
    timeclockActive = false,
    archivedChannels = [],
    sections = [],
    onCreateChannel,
    onStartDirectMessage,
    onInvitePeople,
    userMenu,
}: ChannelSidebarProps) {
    const { t } = useTranslate();

    const threadsByChannel = useMemo(
        () => groupByChannel(activeThreads),
        [activeThreads],
    );

    /*
     * The inbox badge counts more than the ones below it — replies and poll
     * answers as well as mentions — so it cannot be summed from this list the
     * way it used to be. The server counts it and keeps it moving over the
     * socket; the prop is the floor it falls back to on every page load.
     */
    const { auth } = usePage<{ auth: Auth }>().props;

    const inbox = useInboxActivity(auth.user.id, workspace.id, inboxUnread);

    /*
     * Favourites in their own group above the rest, rather than sorted to the
     * top of one list: a list that silently reorders itself is one people stop
     * being able to point at, where a heading says what happened.
     */
    const favorites = channels.filter((channel) => channel.isFavorite);

    /*
     * A channel appears once. Favourites win over a section — somebody who
     * starred it asked for it at the top — and everything left over falls into
     * the ordinary list.
     */
    const filed = new Set(sections.flatMap((section) => section.channelIds));
    const inSection = (id: number) => filed.has(id);

    const ordinary = channels.filter(
        (channel) => !channel.isFavorite && !inSection(channel.id),
    );

    return (
        <div className="flex h-full shrink-0">
            {/*
                The workspace's own entries, in a rail of their own. They used
                to sit at the top of this list and cost four rows of a column
                that is 16rem wide and does not grow.
            */}
            <WorkspaceRail
                workspace={workspace}
                inboxTotal={inbox}
                hasTickets={channels.some((row) => row.hasTickets)}
                hasTransfers={workspace.transfers !== null}
                mentionsActive={mentionsActive}
                savedActive={savedActive}
                transfersActive={transfersActive}
                timeclockActive={timeclockActive}
                ticketsActive={ticketsActive}
                boardActive={boardActive}
                secretsActive={secretsActive}
                formsActive={formsActive}
                onBroadcast={onBroadcast}
            />

            <aside className="flex h-full w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
                <div className="flex h-14 items-center border-b border-sidebar-border px-2">
                    <WorkspaceMenu
                        workspaces={workspaces}
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
                        {t('search.palette.title')}
                        <kbd className="ml-auto rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
                            ⌘K
                        </kbd>
                    </Button>
                </div>

                <ScrollArea className="flex-1 px-2 pb-4">
                    {favorites.length > 0 && (
                        <>
                            <SectionHeading>
                                {t('sidebar.headings.favorites')}
                            </SectionHeading>
                            <div className="mb-2 space-y-0.5">
                                {favorites.map((channel) => (
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
                            </div>
                        </>
                    )}

                    {sections.map((section) => {
                        const rows = channels.filter(
                            (channel) =>
                                !channel.isFavorite &&
                                section.channelIds.includes(channel.id),
                        );

                        return (
                            <div key={section.id} className="mb-2">
                                <SectionHeadingRow
                                    workspaceSlug={workspace.slug}
                                    section={section}
                                />
                                <div className="space-y-0.5">
                                    {rows.map((channel) => (
                                        <ChannelRow
                                            key={channel.id}
                                            workspaceSlug={workspace.slug}
                                            channel={channel}
                                            active={
                                                channel.id === activeChannelId
                                            }
                                            threads={
                                                threadsByChannel.get(
                                                    channel.id,
                                                ) ?? []
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
                                            {t('sidebar.section.empty')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        );
                    })}

                    <SectionHeading>
                        {t('sidebar.headings.channels')}
                    </SectionHeading>
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
                        {channels.length === 0 &&
                            !workspace.canCreateChannel && (
                                <p className="px-2 py-1 text-sm text-muted-foreground">
                                    {t('sidebar.channel.none')}
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
                                    ? t('sidebar.channel.first_channel')
                                    : t('sidebar.channel.add_channel')}
                            </button>
                        )}
                    </div>

                    <SectionHeading>
                        {t('sidebar.headings.directs')}
                    </SectionHeading>
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
                                    {t('sidebar.channel.no_directs')}
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
                                    ? t('sidebar.channel.start_conversation')
                                    : t('sidebar.channel.new_conversation')}
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
                                {t('sidebar.headings.archived')}
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
                                        title={t('sidebar.channel.restore', {
                                            channel: channel.label,
                                        })}
                                        aria-label={t(
                                            'sidebar.channel.restore',
                                            { channel: channel.label },
                                        )}
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
        </div>
    );
}
