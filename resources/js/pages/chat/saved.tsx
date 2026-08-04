import { Head, Link } from '@inertiajs/react';
import { Bookmark, Hash, Lock, MessageSquare } from 'lucide-react';
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
import { show } from '@/routes/chat';
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

/** One message this member set aside. */
interface SavedRow {
    id: number;
    /** The message that named them; the id doubles as the anchor to jump to. */
    messageId: string;
    author: string;
    snippet: string;
    lastReplyAt: string | null;
    /** When it was set aside; the list runs newest first. */
    savedAt: string | null;
    channel: {
        id: number;
        label: string;
        type: ChannelType;
    };
}

interface SavedProps {
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
    saved: SavedRow[];
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

/**
 * Everything you set aside, in one list.
 *
 * The twin of the mention list beside it, and deliberately built the same way —
 * the two answer neighbouring questions and should not feel like two different
 * products. What differs is the ordering: most recently saved first, because
 * saving is an act of "later" and the last thing you meant to come back to is
 * usually the nearest.
 *
 * Nobody else ever sees this. A pin is the channel's; this is yours.
 */
export default function WorkspaceSaved({
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
    saved,
}: SavedProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    useSessionGuard();

    const [searchOpen, setSearchOpen] = useState(false);

    useCommandPaletteShortcut(setSearchOpen);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-screen overflow-hidden bg-background">
            <Head title="Bewaard" />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                savedActive
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
                    <Bookmark className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('screens.saved.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {saved.length === 1
                                ? '1 bericht om op terug te komen'
                                : `${saved.length} berichten om op terug te komen`}
                        </p>
                    </div>
                </header>

                <div className="flex-1 overflow-y-auto p-4">
                    {saved.length === 0 ? (
                        <div className="mx-auto mt-12 max-w-md rounded-lg border border-dashed p-8 text-center">
                            <Bookmark className="mx-auto size-6 text-muted-foreground" />
                            <p className="mt-3 text-sm font-medium">
                                {t('screens.saved.empty')}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('screens.saved.empty_hint')}
                            </p>
                        </div>
                    ) : (
                        <ul className="mx-auto flex max-w-3xl flex-col gap-2">
                            {saved.map((mention) => (
                                <li key={mention.id}>
                                    {/*
                                        Straight to the message, not just to the
                                        channel: the point of the list is to
                                        answer somebody, and landing at the
                                        bottom of a busy channel means finding
                                        the line again yourself.
                                    */}
                                    <Link
                                        href={`${show.url({
                                            workspace: workspace.slug,
                                            channel: mention.channel.id,
                                        })}#message-${mention.messageId}`}
                                        className={cn(
                                            'flex flex-col gap-1 rounded-lg border p-3 transition-colors hover:bg-muted/50',
                                            'border-amber-500/30',
                                        )}
                                    >
                                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <ChannelIcon
                                                type={mention.channel.type}
                                            />
                                            <span className="truncate font-medium text-foreground/80">
                                                {mention.channel.label}
                                            </span>
                                            <span aria-hidden>·</span>
                                            <span className="truncate">
                                                {mention.author}
                                            </span>
                                            {mention.savedAt && (
                                                <>
                                                    <span aria-hidden>·</span>
                                                    <span className="shrink-0">
                                                        {t('screens.saved.at', {
                                                            moment: formats.moment.format(
                                                                new Date(
                                                                    mention.savedAt,
                                                                ),
                                                            ),
                                                        })}
                                                    </span>
                                                </>
                                            )}
                                        </span>
                                        <span className="text-sm break-words text-foreground/90">
                                            {mention.snippet}
                                        </span>
                                    </Link>
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
