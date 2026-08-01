import { Head, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { Conversation } from '@/components/chat/conversation';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useSidebarActivity } from '@/hooks/use-sidebar-activity';
import { useStatusActivity } from '@/hooks/use-status-activity';
import { useThreadActivity } from '@/hooks/use-thread-activity';
import type { Auth } from '@/types';
import type {
    ActiveChannel,
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChannelView,
    ChatMessage,
    ChatWorkspace,
    OpenThread,
    OpenTicket,
    PinnedMessage,
    ScheduledMessage,
    TicketBoard,
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
    bookmarkedIds,
    scheduled,
}: ChatShowProps) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();
    const [searchOpen, setSearchOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

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

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setSearchOpen((open) => !open);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    /*
        Bottom of the sidebar rather than the top right of the conversation: it
        is about you, not about the channel you happen to have open, and the
        conversation header is for the conversation. Wide enough to carry your
        name and your status, which is what makes a status worth setting — you
        see your own the whole time.
    */
    const userMenu = (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:outline-none">
                <Avatar className="size-8 shrink-0">
                    <AvatarFallback className="text-xs font-semibold">
                        {getInitials(auth.user.name)}
                    </AvatarFallback>
                </Avatar>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                        {auth.user.name}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {auth.user.status_text
                            ? `${auth.user.status_emoji ?? ''} ${auth.user.status_text}`.trim()
                            : 'Status instellen'}
                    </span>
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-56">
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title={channel.label} />

            <ChannelSidebar
                workspace={workspace}
                channels={withActivity(channels)}
                directMessages={withActivity(directMessages)}
                activeThreads={activeThreads}
                activeChannelId={channel.id}
                workspaceTags={workspaceTags}
                archivedChannels={archivedChannels}
                sections={sections}
                onOpenSearch={() => setSearchOpen(true)}
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
                messages={messages}
                thread={thread}
                pins={pins}
                view={view}
                tickets={tickets}
                ticket={ticket}
                channels={withActivity(channels)}
                workspaceTags={workspaceTags}
                sections={sections}
                bookmarkedIds={bookmarkedIds}
                scheduled={scheduled}
                currentUser={{ id: auth.user.id, name: auth.user.name }}
                currentUsername={auth.user.username as string | undefined}
                currentUserIsGuest={auth.workspaceRole === 'guest'}
            />

            <BroadcastDialog
                workspace={workspace}
                channels={channels}
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />

            <SearchDialog
                workspace={workspace}
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
