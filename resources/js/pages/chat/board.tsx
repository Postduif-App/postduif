import { Head, router } from '@inertiajs/react';
import { MessageSquare, Pin, Plus } from 'lucide-react';
import { useState } from 'react';

import { BoardPanel } from '@/components/chat/board-panel';
import { BroadcastDialog } from '@/components/chat/broadcast-dialog';
import { ChannelMenuButton } from '@/components/chat/channel-menu';
import { ChannelSidebar } from '@/components/chat/channel-sidebar';
import { CreateBoardPostDialog } from '@/components/chat/create-board-post-dialog';
import { CreateChannelDialog } from '@/components/chat/create-channel-dialog';
import { InvitePeopleDialog } from '@/components/chat/invite-people-dialog';
import { NewDirectMessageDialog } from '@/components/chat/new-direct-message-dialog';
import { SearchDialog } from '@/components/chat/search-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { UserMenu } from '@/components/user-menu-content';
import { useCommandPaletteShortcut } from '@/hooks/use-command-palette-shortcut';
import { useFormats } from '@/hooks/use-formats';
import { useInitials } from '@/hooks/use-initials';
import { useSessionGuard } from '@/hooks/use-session-guard';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { index as boardIndex } from '@/routes/chat/board';
import type {
    ActiveThread,
    ArchivedChannel,
    BoardPostSummary,
    ChannelSection as ChannelSectionRow,
    ChannelSummary,
    ChatWorkspace,
    ScheduledBroadcast,
    OpenBoardPost,
    WorkspaceOption,
} from '@/types/chat';

interface BoardPageProps {
    workspace: ChatWorkspace;
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    activeThreads: ActiveThread[];
    workspaceTags: string[];
    archivedChannels: ArchivedChannel[];
    sections: ChannelSectionRow[];
    inboxUnread: number;
    /** Announcements this member has waiting, for the broadcast dialog. */
    scheduledBroadcasts: ScheduledBroadcast[];
    workspaces: WorkspaceOption[];
    /** What is on the board, pinned first and newest under that. */
    posts: BoardPostSummary[];
    /** The notice named by ?post= in the URL, or null. */
    post: OpenBoardPost | null;
    /** Whether that notice fills the screen instead of sharing it with the list. */
    fullscreen: boolean;
    /** Whether this member may put something up at all. */
    canPost: boolean;
}

/**
 * Het prikbord: what the workspace has put up, for everybody in it.
 *
 * The same shell as the ticket list and the transfer list, and the same
 * list-beside-panel shape — but note what is deliberately missing against
 * tickets: no filters, no statuses, no counts. A ticket list is a queue you work
 * through and want to slice; a board is a wall you glance at. Adding filters
 * here would be adding the one thing that makes people stop glancing.
 */
export default function Board({
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
    posts,
    post,
    fullscreen,
    canPost,
}: BoardPageProps) {
    const { t } = useTranslate();
    const formats = useFormats();

    useSessionGuard();

    const getInitials = useInitials();

    const [searchOpen, setSearchOpen] = useState(false);
    useCommandPaletteShortcut(setSearchOpen);

    const [createOpen, setCreateOpen] = useState(false);
    const [directOpen, setDirectOpen] = useState(false);
    const [inviteOpen, setInviteOpen] = useState(false);
    const [broadcastOpen, setBroadcastOpen] = useState(false);
    const [writeOpen, setWriteOpen] = useState(false);
    /*
        Bumped every time the dialog opens and used as its key, the same way the
        conversation does it: a fresh mount is what clears the fields, so no
        effect has to write state on open and no half-typed notice is left over
        from last time.
    */
    const [writeKey, setWriteKey] = useState(0);

    /**
     * Open a notice, or close the one that is open.
     *
     * Through the URL rather than through component state, which is the whole
     * reason it is a visit and not a setState: a notice somebody wants a
     * colleague to read has to be something they can send.
     *
     * How it opens travels the same way. Closing drops both keys at once, so
     * nobody lands back on the board with a `full` still hanging off the URL
     * waiting to surprise them the next time they click a notice.
     */
    const open = (id: string | null, full: boolean = fullscreen) => {
        router.visit(
            boardIndex(
                workspace.slug,
                id === null
                    ? {}
                    : { query: full ? { post: id, full: 1 } : { post: id } },
            ),
            { preserveScroll: true, preserveState: true },
        );
    };

    /**
     * Reading a notice on the whole screen, or handing the list its half back.
     *
     * Worth having for the notice the board is actually for: the year plan, the
     * house rules, the long one with a table in it. Those are written to be read
     * rather than glanced at, and a column of 28rem turns three paragraphs into
     * eleven.
     */
    const expanded = post !== null && fullscreen;

    const userMenu = <UserMenu />;

    return (
        <div className="flex h-dvh overflow-hidden bg-background">
            <Head title="Prikbord" />

            <ChannelSidebar
                workspace={workspace}
                inboxUnread={inboxUnread}
                workspaces={workspaces}
                channels={channels}
                directMessages={directMessages}
                activeThreads={activeThreads}
                activeChannelId={null}
                boardActive
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
                Weg in plaats van smaller: een lijst die naast een volledig
                scherm blijft staan is geen lijst meer maar een rand. De sidebar
                blijft wel staan — die is navigatie, en wie een lang bericht
                leest wil nog steeds naar een kanaal kunnen springen.
            */}
            <main
                className={cn(
                    'min-w-0 flex-1 flex-col',
                    expanded ? 'hidden' : 'flex',
                )}
            >
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                    <ChannelMenuButton />
                    <Pin className="size-4 text-muted-foreground" />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {t('screens.board.title')}
                        </h1>
                        <p className="truncate text-xs text-muted-foreground">
                            {t('screens.board.intro', {
                                workspace: workspace.name,
                            })}
                        </p>
                    </div>

                    {canPost && (
                        <Button
                            size="sm"
                            className="ml-auto"
                            onClick={() => {
                                setWriteKey((key) => key + 1);
                                setWriteOpen(true);
                            }}
                        >
                            <Plus className="size-4" />
                            {t('screens.board.new')}
                        </Button>
                    )}
                </header>

                <div className="flex-1 overflow-y-auto">
                    {posts.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            {t('screens.board.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {posts.map((row) => (
                                <li key={row.id}>
                                    <button
                                        type="button"
                                        onClick={() => open(row.id)}
                                        className={cn(
                                            'flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50',
                                            post?.id === row.id && 'bg-muted',
                                        )}
                                    >
                                        {/*
                                            The pin keeps a column of its own
                                            rather than sitting inline with the
                                            title: at a glance the eye is looking
                                            down one edge for what was singled
                                            out, and a marker that moves with the
                                            text length is one it has to hunt for.
                                        */}
                                        <span className="w-4 shrink-0 pt-0.5">
                                            {row.pinned && (
                                                <Pin
                                                    className="size-3.5 text-primary"
                                                    aria-label="Vastgezet"
                                                />
                                            )}
                                        </span>

                                        {/*
                                            Wie het ophing, naast wat er hangt.
                                            Een prikbord is geen postvak: je
                                            zoekt er even vaak op "waar stond dat
                                            van Anna" als op de titel, en een
                                            gezicht vind je sneller terug dan een
                                            naam onderaan drie regels tekst. De
                                            naam blijft er onder staan, want een
                                            avatar alleen zegt niets tegen wie
                                            hier net binnen is.
                                        */}
                                        <Avatar className="mt-0.5 size-8 shrink-0 rounded-md">
                                            {row.author?.avatarUrl && (
                                                <AvatarImage
                                                    src={row.author.avatarUrl}
                                                    alt=""
                                                    className="rounded-md"
                                                />
                                            )}
                                            <AvatarFallback className="rounded-md text-[10px] font-semibold">
                                                {/*
                                                    Een streepje voor wie hier
                                                    weg is: initialen van een
                                                    placeholdernaam zouden een
                                                    "O" tonen die op een collega
                                                    lijkt.
                                                */}
                                                {row.author
                                                    ? getInitials(
                                                          row.author.name,
                                                      )
                                                    : '–'}
                                            </AvatarFallback>
                                        </Avatar>

                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">
                                                {row.title}
                                            </span>
                                            <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                                                {row.excerpt}
                                            </span>
                                            <span className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                <span className="truncate">
                                                    {row.author?.name ??
                                                        'Oud-collega'}
                                                    {row.createdAt &&
                                                        ` · ${formats.mediumDate.format(new Date(row.createdAt))}`}
                                                    {row.editedAt &&
                                                        ' · aangepast'}
                                                </span>
                                                {row.commentCount > 0 && (
                                                    <span className="flex shrink-0 items-center gap-1">
                                                        <MessageSquare className="size-3" />
                                                        {row.commentCount}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>

            {post && (
                <BoardPanel
                    workspace={workspace}
                    post={post}
                    fullscreen={expanded}
                    onToggleFullscreen={() => open(post.id, !expanded)}
                    onClose={() => open(null)}
                />
            )}

            <CreateBoardPostDialog
                key={writeKey}
                workspace={workspace}
                open={writeOpen}
                onOpenChange={setWriteOpen}
            />

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
                    channels={channels.filter((row) => row.type !== 'dm')}
                    open={inviteOpen}
                    onOpenChange={setInviteOpen}
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
        </div>
    );
}
