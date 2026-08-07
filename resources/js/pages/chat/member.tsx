import { Head } from '@inertiajs/react';
import { Clock, Hash } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useInitials } from '@/hooks/use-initials';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    WorkspaceOption,
} from '@/types/chat';

/** One member, as their own page shows them. */
export interface MemberProfile {
    id: number;
    name: string;
    username: string;
    avatarUrl: string | null;
    /** Null when they have never written one. */
    bio: string | null;
    timezone: string;
    /** What the clock says where they are, worked out on the server. */
    localTime: string;
    status: {
        emoji: string | null;
        text: string | null;
        availability: string;
        label: string;
    };
    role: string | null;
    roleLabel: string | null;
    joinedAt: string | null;
    isYou: boolean;
}

interface MemberPageProps {
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
    member: MemberProfile;
}

/**
 * Who somebody is, reachable from their name anywhere it appears.
 *
 * The same shell every other cross-channel screen uses, because this is a place
 * you step into for a moment and step back out of — losing the sidebar you came
 * from would make going back a navigation instead of a glance.
 */
export default function WorkspaceMember({
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
    member,
}: MemberPageProps) {
    useSessionGuard();

    const getInitials = useInitials();
    const formats = useFormats();
    const { t } = useTranslate();

    const [searchOpen, setSearchOpen] = useState(false);

    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title={member.name} />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                transfersActive
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
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <ChannelMenuButton />
                    <h1 className="truncate text-sm font-semibold">
                        {member.name}
                    </h1>
                    {member.roleLabel && (
                        <span className="shrink-0 rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
                            {member.roleLabel}
                        </span>
                    )}
                </header>

                <div className="flex-1 overflow-y-auto p-4">
                    <div className="mx-auto flex max-w-xl flex-col gap-6">
                        <div className="flex items-start gap-4">
                            <Avatar className="size-16 shrink-0 rounded-lg">
                                {member.avatarUrl && (
                                    <AvatarImage
                                        src={member.avatarUrl}
                                        alt=""
                                    />
                                )}
                                <AvatarFallback className="rounded-lg text-lg font-semibold">
                                    {getInitials(member.name)}
                                </AvatarFallback>
                            </Avatar>

                            <div className="min-w-0 flex-1">
                                <p className="text-lg font-semibold">
                                    {member.name}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    @{member.username}
                                </p>

                                {(member.status.emoji ||
                                    member.status.text) && (
                                    <p className="mt-2 flex items-center gap-1.5 text-sm">
                                        {member.status.emoji && (
                                            <span aria-hidden>
                                                {member.status.emoji}
                                            </span>
                                        )}
                                        <span className="text-foreground/80">
                                            {member.status.text ??
                                                member.status.label}
                                        </span>
                                    </p>
                                )}
                            </div>
                        </div>

                        {/*
                            Only when there is something to read. An empty
                            heading saying "Over" is a promise the page cannot
                            keep, and most people never fill this in.
                        */}
                        {member.bio && (
                            <p className="text-sm leading-relaxed break-words text-foreground/90">
                                {member.bio}
                            </p>
                        )}

                        <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                            {/*
                                The time where they are, not the zone name. What
                                somebody is actually asking is whether they can
                                message now, and "Europe/Amsterdam" makes them do
                                the arithmetic themselves.
                            */}
                            <p className="flex items-center gap-2">
                                <Clock className="size-4 shrink-0 text-muted-foreground" />
                                <span>{member.localTime}</span>
                                <span className="truncate text-muted-foreground">
                                    {member.timezone}
                                </span>
                            </p>

                            {member.joinedAt && (
                                <p className="flex items-center gap-2 text-muted-foreground">
                                    <Hash className="size-4 shrink-0" />
                                    <span>
                                        {t('profile.member_since', {
                                            date: formats.date.format(
                                                new Date(member.joinedAt),
                                            ),
                                        })}
                                    </span>
                                </p>
                            )}
                        </div>
                    </div>
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
