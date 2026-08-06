import { Head, Link } from '@inertiajs/react';
import { Hash, Inbox, Lock, MessageSquare } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as inboxIndex, open as inboxOpen } from '@/routes/chat/inbox';
import { index as mentionsIndex } from '@/routes/chat/mentions';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChannelType,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/** Why one row is in the inbox. Mirrors App\Enums\InboxItemType. */
type InboxItemType = 'mention' | 'reply' | 'thread-reply' | 'poll-vote';

/** What every row carries, whatever put it there. */
interface InboxRowBase {
    id: number;
    type: InboxItemType;
    /** The reason in one word, as the tab that holds it calls it. */
    label: string;
    /** Null while it still wants an answer. */
    readAt: string | null;
    /** Who acted. Empty on a poll row, which stands for every vote at once. */
    actor: string | null;
    channel: {
        id: number;
        label: string;
        type: ChannelType;
    };
}

/** A row that points at something somebody wrote. */
interface InboxMessageRow extends InboxRowBase {
    type: Exclude<InboxItemType, 'poll-vote'>;
    /**
     * The message it hangs off. Not what the row links to — the server works
     * the destination out and forwards there, anchor and all, so that a row
     * cannot be jumped to without also being marked off.
     */
    messageId: string;
    author: string;
    snippet: string;
    lastReplyAt: string | null;
}

/** A row that points at a question rather than at a message. */
interface InboxPollRow extends InboxRowBase {
    type: 'poll-vote';
    poll: {
        id: string;
        question: string;
        voterCount: number;
    };
}

type InboxRow = InboxMessageRow | InboxPollRow;

/** The tabs, in the order they are offered. Null is "everything". */
const TABS: { value: InboxItemType | null; label: string }[] = [
    { value: null, label: 'Alles' },
    { value: 'mention', label: 'Genoemd' },
    { value: 'reply', label: 'Antwoorden' },
    { value: 'thread-reply', label: 'Threads' },
    { value: 'poll-vote', label: 'Polls' },
];

interface InboxProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    /** Channels that were put away, for whoever may take them back out. */
    archivedChannels: ArchivedChannel[];
    /** The groups this member arranged for themselves. */
    sections: ChannelSectionRow[];
    /** Unread inbox rows of every kind, counted by the server. */
    inboxUnread: number;
    /** Announcements this member has waiting, for the broadcast dialog. */
    scheduledBroadcasts: ScheduledBroadcast[];
    /** Every workspace this member belongs to, for the switcher up top. */
    workspaces: WorkspaceOption[];
    items: InboxRow[];
    /** Which tab the server answered with; null when it is showing everything. */
    filter: InboxItemType | null;
}

function ChannelIcon({ type }: { type: ChannelType }) {
    const className = 'size-3.5 shrink-0 text-muted-foreground';

    if (type === 'private') {
        return <Lock className={className} />;
    }

    if (type === 'dm') {
        return <MessageSquare className={className} />;
    }

    return <Hash className={className} />;
}

/** The line above a row: where it came from, why, and when. */
function RowMeta({ item }: { item: InboxRow }) {
    return (
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <ChannelIcon type={item.channel.type} />
            <span className="truncate font-medium text-foreground/80">
                {item.channel.label}
            </span>
            <span aria-hidden>·</span>
            <span className="shrink-0">{item.label}</span>
            {item.actor && (
                <>
                    <span aria-hidden>·</span>
                    <span className="truncate">{item.actor}</span>
                </>
            )}
        </span>
    );
}

/**
 * One row, wherever it points.
 *
 * Every row leads to the same address — the row itself — and the server marks
 * it off and forwards from there. Kept a link rather than a button that posts,
 * because middle-click and open-in-a-new-tab are how a list of things to read
 * gets used, and because a separate mark-read request would race the
 * navigation: Inertia cancels an in-flight visit when a new one starts.
 *
 * Which is also why nothing here knows where a row goes. A poll row and a
 * message row have genuinely different destinations, and that is a fact about
 * what the row hangs off — the same knowledge the server already needs to
 * decide the row is still worth showing at all.
 */
function InboxCard({
    item,
    workspaceSlug,
}: {
    item: InboxRow;
    workspaceSlug: string;
}) {
    const formats = useFormats();

    return (
        <Link
            href={inboxOpen({ workspace: workspaceSlug, item: item.id })}
            className={cn(
                'flex flex-col gap-1 rounded-lg border p-3 transition-colors hover:bg-muted/50',
                item.readAt === null && 'border-primary/40 bg-primary/5',
            )}
        >
            <RowMeta item={item} />

            {item.type === 'poll-vote' ? (
                <>
                    <span className="text-sm break-words text-foreground/90">
                        {item.poll.question}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {item.poll.voterCount === 1
                            ? '1 iemand heeft gestemd'
                            : `${item.poll.voterCount} mensen hebben gestemd`}
                    </span>
                </>
            ) : (
                <>
                    <span className="text-sm break-words text-foreground/90">
                        {item.snippet}
                    </span>
                    {item.lastReplyAt && (
                        <span className="text-xs text-muted-foreground">
                            {formats.moment.format(new Date(item.lastReplyAt))}
                        </span>
                    )}
                </>
            )}
        </Link>
    );
}

/**
 * Everything that asks something of you, in one list.
 *
 * Unread first and newest within that, because the question this screen answers
 * is "what is being asked of me" rather than "what happened". A row is marked
 * off by being opened — see InboxCard — and a mention additionally by reading
 * past it in its channel, which is the same event arrived at from the other
 * side. The ordering is the server's answer and does not shuffle underneath
 * somebody as they read: a row that goes read stays where it is until the next
 * load.
 */
export default function WorkspaceInbox({
    workspace,
    channels,
    directMessages,
    activeThreads,
    workspaceTags,
    archivedChannels,
    sections,
    inboxUnread,
    scheduledBroadcasts,
    workspaces,
    items,
    filter,
}: InboxProps) {
    const { t } = useTranslate();

    useSessionGuard();

    const [searchOpen, setSearchOpen] = useState(false);

    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const unread = items.filter((item) => item.readAt === null).length;

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title="Vermeldingen" />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                mentionsActive
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={userMenu}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex shrink-0 flex-col gap-2 border-b px-4 py-3">
                    <div className="flex items-center gap-3">
                        <Inbox className="size-4 text-muted-foreground" />
                        <div className="min-w-0">
                            <h1 className="truncate text-sm font-semibold">
                                {t('screens.inbox.title')}
                            </h1>
                            <p className="truncate text-xs text-muted-foreground">
                                {unread === 0
                                    ? 'Alles gelezen'
                                    : unread === 1
                                      ? '1 wacht nog op je'
                                      : `${unread} wachten nog op je`}
                            </p>
                        </div>
                    </div>

                    {/*
                        Links rather than local state: the filter is the server's
                        answer, so a tab has to be somewhere you can land, share
                        and go back to. The mentions tab keeps its own address,
                        which is where the sidebar badge has always pointed.
                    */}
                    <nav className="-mx-1 flex gap-1 overflow-x-auto">
                        {TABS.map((tab) => (
                            <Link
                                key={tab.value ?? 'all'}
                                href={
                                    tab.value === 'mention'
                                        ? mentionsIndex(workspace.slug)
                                        : inboxIndex(workspace.slug, {
                                              query: tab.value
                                                  ? { type: tab.value }
                                                  : {},
                                          })
                                }
                                className={cn(
                                    'shrink-0 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                    filter === tab.value
                                        ? 'bg-primary/10 text-foreground'
                                        : 'text-muted-foreground hover:bg-muted/60',
                                )}
                            >
                                {tab.label}
                            </Link>
                        ))}
                    </nav>
                </header>

                <div className="flex-1 overflow-y-auto p-4">
                    {items.length === 0 ? (
                        <div className="mx-auto mt-12 max-w-md rounded-lg border border-dashed p-8 text-center">
                            <Inbox className="mx-auto size-6 text-muted-foreground" />
                            <p className="mt-3 text-sm font-medium">
                                {t('screens.inbox.empty')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('screens.inbox.empty_hint')}
                            </p>
                        </div>
                    ) : (
                        <ul className="mx-auto flex max-w-3xl flex-col gap-2">
                            {items.map((item) => (
                                <li key={item.id}>
                                    <InboxCard
                                        item={item}
                                        workspaceSlug={workspace.slug}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>

            <SearchDialog
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                actions={{
                    onCreateChannel: workspace.canCreateChannel
                        ? () => setCreateOpen(true)
                        : undefined,
                    onStartDirectMessage: workspace.canStartDirectMessage
                        ? () => setDirectOpen(true)
                        : undefined,
                    onInvitePeople: workspace.canInvite
                        ? () => setInviteOpen(true)
                        : undefined,
                    onBroadcast: workspace.canBroadcastToChannels
                        ? () => setBroadcastOpen(true)
                        : undefined,
                }}
                open={searchOpen}
                onOpenChange={setSearchOpen}
            />
            <CreateChannelDialog
                workspace={workspace}
                open={createOpen}
                onOpenChange={setCreateOpen}
            />
            <NewDirectMessageDialog
                workspace={workspace}
                open={directOpen}
                onOpenChange={setDirectOpen}
            />
            <InvitePeopleDialog
                workspace={workspace}
                channels={channels}
                open={inviteOpen}
                onOpenChange={setInviteOpen}
            />
            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                scheduledBroadcasts={scheduledBroadcasts}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />
        </div>
    );
}
