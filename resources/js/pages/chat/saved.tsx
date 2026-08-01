import { Head, Link, usePage } from '@inertiajs/react';
import { Bookmark, Hash, Lock, MessageSquare } from 'lucide-react';
import { useState } from 'react';

import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import type { Auth } from '@/types';
import type {
    ActiveThread,
    ArchivedChannel,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChannelType,
    ChatWorkspace,
} from '@/types/chat';

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
});

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
    saved,
}: SavedProps) {
    useSessionGuard();

    const { auth } = usePage<{ auth: Auth }>().props;
    const getInitials = useInitials();

    const [searchOpen, setSearchOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);

    const userMenu = (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-sidebar-accent/50 focus-visible:ring-2 focus-visible:outline-none">
                <Avatar className="size-8 shrink-0">
                    {/*
                        Above the fallback rather than instead of it: Radix
                        draws the initials until the picture has loaded, and
                        keeps them if it never does.
                    */}
                    {auth.avatarUrl && (
                        <AvatarImage src={auth.avatarUrl} alt="" />
                    )}
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
            <Head title="Bewaard" />

            <ChannelSidebar
                workspace={workspace}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                savedActive
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

            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <Bookmark className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            Bewaard
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
                                Je hebt nog niets bewaard
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Zweef over een bericht en klik op het
                                bladwijzertje. Alleen jij ziet wat hier staat.
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
                                                        bewaard{' '}
                                                        {MOMENT_FORMAT.format(
                                                            new Date(
                                                                mention.savedAt,
                                                            ),
                                                        )}
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
                tags={workspaceTags}
                open={broadcastOpen}
                onOpenChange={setBroadcastOpen}
            />
        </div>
    );
}
