import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { Conversation } from '@/components/chat/conversation';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { MemberPanel } from '@/components/chat/member-panel';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { PollDialog } from '@/components/chat/poll-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { SecretRequestDialog } from '@/components/chat/secret-request-dialog';
import { SendSecretDialog } from '@/components/chat/send-secret-dialog';
import { TransferDialog } from '@/components/chat/transfer-dialog';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useSidebarActivity } from '@/hooks/use-sidebar-activity';
import { useStatusActivity } from '@/hooks/use-status-activity';
import { useThreadActivity } from '@/hooks/use-thread-activity';
import { withFilter } from '@/lib/search-filters';
import type { Auth } from '@/types';
import type {
    ActiveChannel,
    ActiveThread,
    ArchivedChannel,
    ChannelMember,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChannelView,
    ChatMessage,
    ChatWorkspace,
    ScheduledBroadcast,
    OpenThread,
    OpenTicket,
    PinnedMessage,
    ScheduledMessage,
    TicketBoard,
    WorkspaceOption,
} from '@/types/chat';

interface ChatShowProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    channel: ActiveChannel;
    messages: ChatMessage[];
    thread: OpenThread | null;
    /** What is pinned in this channel, oldest pin first. */
    pins: PinnedMessage[];
    /** Whether the channel opens on its messages or its board. */
    view: ChannelView;
    /** The channel's tickets, or null when it keeps none at all. */
    tickets: TicketBoard | null;
    /** The ticket named by ?ticket= in the URL, or null. */
    ticket: OpenTicket | null;
    /** Every tag in use in the workspace, for the channel settings dialog. */
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
    /** Everybody in the workspace, or empty when this member is not shown them. */
    workspaceMembers: ChannelMember[];
    /** Whether the panel was left open, remembered in a cookie. */
    memberPanelOpen: boolean;
    /** Which of the messages above this member set aside for later. */
    bookmarkedIds: string[];
    /** What this member still has waiting in this channel. */
    scheduled: ScheduledMessage[];
}

export default function ChatShow({
    workspace,
    channels,
    directMessages,
    activeThreads,
    channel,
    messages,
    thread,
    pins,
    view,
    tickets,
    ticket,
    workspaceTags,
    archivedChannels,
    sections,
    inboxUnread,
    scheduledBroadcasts,
    workspaces,
    workspaceMembers,
    memberPanelOpen,
    bookmarkedIds,
    scheduled,
}: ChatShowProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [searchOpen, setSearchOpen] = useState(false);

    /*
     * What the palette starts with when the channel header opens it. Cleared
     * when it is opened any other way, so ⌘K never inherits a filter from the
     * last time somebody used the button.
     */
    const [searchPrefill, setSearchPrefill] = useState<string | undefined>();
    const [createOpen, setCreateOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    /*
     * Seeded from the server so the first paint is already right, then written
     * back to the same cookie. A panel that flicks open and closes again on
     * every load is worse than one that forgets where it was.
     */
    const [membersOpen, setMembersOpen] = useState(memberPanelOpen);

    const toggleMembers = (open: boolean) => {
        setMembersOpen(open);
        document.cookie = `member_panel_state=${open}; path=/; max-age=${60 * 60 * 24 * 365}`;
    };

    // Messages keep arriving over the socket long after a session ends, so the
    // page has to notice on its own that nobody is signed in any more.
    useSessionGuard();

    // Server counts, plus whatever arrived over the socket since they were
    // rendered. Without this a badge only appears once you navigate.
    const deltas = useSidebarActivity(auth.user.id, channel.id);

    // The thread list cannot be patched from a socket payload the way a badge
    // can, so a reply asks the server for it again.
    useThreadActivity(auth.user.id);

    // Statuses of everyone this member shares a channel with, as they change.
    const liveStatuses = useStatusActivity(auth.user.id);

    const withActivity = (rows: ChannelSummary[]): ChannelSummary[] =>
        rows.map((row) => {
            const delta = deltas[row.id];
            // Only a one-on-one carries a status, and only that person's.
            const status = row.status
                ? liveStatuses[row.status.userId]
                : undefined;

            if (delta === undefined && status === undefined) {
                return row;
            }

            return {
                ...row,
                unreadCount: row.unreadCount + (delta?.unread ?? 0),
                mentionCount: row.mentionCount + (delta?.mentions ?? 0),
                status:
                    status && row.status
                        ? { ...row.status, ...status }
                        : row.status,
            };
        });

    /**
     * The open channel, with any status that has changed since it was rendered.
     *
     * Applied here rather than inside the conversation because the member list
     * feeds three things at once — the header roster, the mention picker and
     * the ticket assignee list — and patching it once keeps them saying the
     * same thing.
     */
    const liveChannel: ActiveChannel = {
        ...channel,
        members: channel.members.map((member) => {
            const status = liveStatuses[member.id];

            return status === undefined
                ? member
                : {
                      ...member,
                      statusEmoji: status.emoji,
                      statusText: status.text,
                      availability: status.availability,
                  };
        }),
    };

    useCommandPaletteShortcut(setSearchOpen);

    /*
     * The two channel-scoped dialogs live here rather than in the conversation
     * or the composer, so that the button beside the message field, the slash
     * commands and the palette all open the same one. They were duplicated
     * before — one instance for the button and one for the command — which is
     * two things that can be open at once.
     */
    const [sendingFiles, setSendingFiles] = useState(false);
    const [askingSecret, setAskingSecret] = useState(false);
    const [sendingSecret, setSendingSecret] = useState(false);
    const [askingPoll, setAskingPoll] = useState(false);

    /*
        Bottom of the sidebar rather than the top right of the conversation: it
        is about you, not about the channel you happen to have open, and the
        conversation header is for the conversation. Wide enough to carry your
        name and your status, which is what makes a status worth setting — you
        see your own the whole time.
    */
    const userMenu = <UserMenu />;

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title={channel.label} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={withActivity(channels)}
                directMessages={withActivity(directMessages)}
                activeThreads={activeThreads}
                activeChannelId={channel.id}
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => {
                    setSearchPrefill(undefined);
                    setSearchOpen(true);
                }}
                onCreateChannel={() => setCreateOpen(true)}
                userMenu={userMenu}
                onStartDirectMessage={() => setDirectOpen(true)}
                onInvitePeople={() => setInviteOpen(true)}
                onBroadcast={() => setBroadcastOpen(true)}
            />

            {/*
                Keyed by channel so every bit of live state — socket
                subscription, presence roster, typing timers, optimistic drafts
                — is thrown away when the member opens another conversation.
            */}
            <Conversation
                key={channel.id}
                workspace={workspace}
                channel={liveChannel}
                onSearchChannel={() => {
                    setSearchPrefill(withFilter('', 'in', channel.label));
                    setSearchOpen(true);
                }}
                messages={messages}
                thread={thread}
                pins={pins}
                view={view}
                tickets={tickets}
                ticket={ticket}
                channels={withActivity(channels)}
                sections={sections}
                workspaceTags={workspaceTags}
                onSendFiles={
                    workspace.transfers
                        ? () => setSendingFiles(true)
                        : undefined
                }
                onAskSecret={
                    workspace.secrets ? () => setAskingSecret(true) : undefined
                }
                onSendSecret={
                    workspace.secrets ? () => setSendingSecret(true) : undefined
                }
                onAskPoll={
                    workspace.polls ? () => setAskingPoll(true) : undefined
                }
                bookmarkedIds={bookmarkedIds}
                scheduled={scheduled}
                currentUser={{ id: auth.user.id, name: auth.user.name }}
                currentUsername={auth.user.username as string | undefined}
                currentUserAvatarUrl={auth.avatarUrl}
                currentUserIsGuest={auth.workspaceIsExternal}
                workspacePanelOpen={membersOpen}
                onToggleWorkspacePanel={
                    workspace.showsMemberPanel
                        ? () => toggleMembers(!membersOpen)
                        : undefined
                }
            />

            {workspace.showsMemberPanel && membersOpen && (
                <MemberPanel
                    members={workspaceMembers}
                    currentUserId={auth.user.id}
                    workspaceSlug={workspace.slug}
                    onClose={() => toggleMembers(false)}
                />
            )}

            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                scheduledBroadcasts={scheduledBroadcasts}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />

            {workspace.transfers && (
                <TransferDialog
                    workspaceSlug={workspace.slug}
                    channelId={channel.id}
                    maxKb={workspace.transfers.maxKb}
                    maxDays={workspace.transfers.maxDays}
                    open={sendingFiles}
                    onOpenChange={setSendingFiles}
                />
            )}

            {workspace.secrets && (
                <SecretRequestDialog
                    workspaceSlug={workspace.slug}
                    channelId={channel.id}
                    open={askingSecret}
                    onOpenChange={setAskingSecret}
                />
            )}

            {workspace.secrets && (
                <SendSecretDialog
                    workspaceSlug={workspace.slug}
                    channelId={channel.id}
                    people={channel.members.filter(
                        (member) => member.id !== auth.user.id,
                    )}
                    open={sendingSecret}
                    onOpenChange={setSendingSecret}
                />
            )}

            {workspace.polls && (
                <PollDialog
                    workspaceSlug={workspace.slug}
                    channelId={channel.id}
                    open={askingPoll}
                    onOpenChange={setAskingPoll}
                />
            )}

            <SearchDialog
                prefill={searchPrefill}
                workspaceMembers={workspaceMembers}
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
                    /*
                     * Only here, and only because this screen has a channel to
                     * act on. The palette also opens on the saved, mentions and
                     * ticket screens, where there is nothing to send a file to.
                     */
                    onSendFiles: workspace.transfers
                        ? () => setSendingFiles(true)
                        : undefined,
                    onAskSecret: workspace.secrets
                        ? () => setAskingSecret(true)
                        : undefined,
                    onSendSecret: workspace.secrets
                        ? () => setSendingSecret(true)
                        : undefined,
                    onAskPoll: workspace.polls
                        ? () => setAskingPoll(true)
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

            {workspace.canStartDirectMessage && (
                <NewDirectMessageDialog
                    workspace={workspace}
                    open={directOpen}
                    onOpenChange={setDirectOpen}
                />
            )}

            {workspace.canInvite && (
                <InvitePeopleDialog
                    workspace={workspace}
                    // The channels this member can see are exactly the ones
                    // they may hand out; DMs are not a thing you get invited
                    // to, so they stay out of the picker.
                    channels={channels.filter((row) => row.type !== 'dm')}
                    initialChannelId={
                        channel.type === 'dm' ? undefined : channel.id
                    }
                    open={inviteOpen}
                    onOpenChange={setInviteOpen}
                />
            )}
        </div>
    );
}
