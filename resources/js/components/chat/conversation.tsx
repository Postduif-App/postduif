import { router } from '@inertiajs/react';
import {
    Search,
    CalendarClock,
    ExternalLink,
    Hash,
    Headphones,
    Lock,
    MessageSquare,
    Settings,
    Star,
    Users,
    Zap,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';

import { ChannelMembersDialog } from '@/components/chat/channel-members-dialog';
import { ChannelSettingsDialog } from '@/components/chat/channel-settings-dialog';
import { Composer } from '@/components/chat/composer';
import { CreateTicketDialog } from '@/components/chat/create-ticket-dialog';
import { FeedList } from '@/components/chat/feed-list';
import { ForwardDialog } from '@/components/chat/forward-dialog';
import { HuddleBar } from '@/components/chat/huddle-bar';
import { HuddleStage } from '@/components/chat/huddle-stage';
import { JoinChannelNotice } from '@/components/chat/join-channel-notice';
import { MessageList } from '@/components/chat/message-list';
import { MuteMenu } from '@/components/chat/mute-menu';
import { NoticeList } from '@/components/chat/notice-list';
import { PinnedBar, PinnedPanel } from '@/components/chat/pinned-messages';
import { ScheduledPanel } from '@/components/chat/scheduled-panel';
import { SectionMenu } from '@/components/chat/section-menu';
import { ThreadPanel } from '@/components/chat/thread-panel';
import { TicketBoard } from '@/components/chat/ticket-board';
import { TicketPanel } from '@/components/chat/ticket-panel';
import { OPEN_STATUSES } from '@/components/chat/ticket-status';
import { TypingIndicator } from '@/components/chat/typing-indicator';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useChannelRealtime } from '@/hooks/use-channel-realtime';
import { useHuddle } from '@/hooks/use-huddle';
import { useTicketActivity } from '@/hooks/use-ticket-activity';
import { useTranslate } from '@/hooks/use-translate';
import { fromLocalInput } from '@/lib/local-datetime';
import { ulid } from '@/lib/ulid';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { favorite, unfavorite } from '@/routes/chat/channels';
import { run as runLink } from '@/routes/chat/channels/links';
import { store as storeScheduled } from '@/routes/chat/channels/scheduled';
import { store as runCommand } from '@/routes/chat/commands';
import { store } from '@/routes/chat/messages';
import {
    bookmark,
    destroy,
    pin,
    unbookmark,
    unpin,
    update,
} from '@/routes/chat/messages';
import { destroy as destroyAttachment } from '@/routes/chat/messages/attachments';
import { store as storeReaction } from '@/routes/chat/messages/reactions';
import type {
    ActiveChannel,
    ChannelSummary,
    ChannelView,
    ChatMessage,
    ChannelSection as ChannelSectionRow,
    ChatWorkspace,
    MessageAttachment,
    MessageAuthor,
    MessageReaction,
    OpenThread,
    OpenTicket,
    PinnedMessage,
    EphemeralNotice,
    ScheduledMessage,
    TicketBoard as Board,
} from '@/types/chat';

interface ConversationProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    /** Messages rendered server-side; authoritative for everything up to now. */
    messages: ChatMessage[];
    /** The thread named by ?thread= in the URL, or null. */
    thread: OpenThread | null;
    /**
     * What is pinned in this channel. Its own list rather than a slice of
     * `messages`: a pin may be far older than the fifty messages above.
     */
    pins: PinnedMessage[];
    /** Whether the channel is showing its messages or its board. */
    view: ChannelView;
    /** The channel's tickets, or null when it keeps none at all. */
    tickets: Board | null;
    /** The ticket named by ?ticket= in the URL, or null. */
    ticket: OpenTicket | null;
    /** What this member still has waiting in this channel, soonest first. */
    scheduled: ScheduledMessage[];
    /** What this member alone was told here, oldest first. */
    notices: EphemeralNotice[];
    /** Channels this reader may open, for rendering and completing #links. */
    channels: ChannelSummary[];
    /** Every tag in use in the workspace, for the settings dialog to suggest. */
    workspaceTags: string[];
    /**
     * Opens the page's own dialog for sending files by link, or absent where
     * this member may not. The dialog lives on the page so the button here, the
     * slash command and the palette all reach the same one.
     */
    onSendFiles?: () => void;
    /** Opens the palette already scoped to this channel. */
    onSearchChannel?: () => void;
    /** The same, for asking somebody for a password or a key. */
    onAskSecret?: () => void;
    /** The mirror of onAskSecret: handing one over instead of asking for one. */
    onSendSecret?: () => void;
    /** The same, for putting a question to the channel. */
    onAskPoll?: () => void;
    /** The groups this member arranged, for filing this channel into one. */
    sections: ChannelSectionRow[];
    /** Which of the messages above this member set aside. */
    bookmarkedIds: string[];
    currentUser: MessageAuthor;
    currentUsername?: string;
    /** The signed-in member's own face, for the optimistic draft. */
    currentUserAvatarUrl?: string | null;
    /** Marks the member's own optimistic drafts, so the badge does not appear
     *  only once the server echo lands. */
    currentUserIsGuest?: boolean;
    /**
     * Whether the workspace member panel beside this conversation is showing.
     * Not to be confused with membersOpen below, which is this channel's own
     * member dialog — different list, different question.
     */
    workspacePanelOpen?: boolean;
    /** Absent when this workspace does not offer the panel at all. */
    onToggleWorkspacePanel?: () => void;
}

/**
 * The look of a button in the bar above the conversation.
 *
 * A constant because two elements wear it — an anchor for a link and a button
 * for a workflow — and the whole point of the bar is that the two read as one
 * row rather than as a row plus something else.
 */
const LINK_BUTTON =
    'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none';

function ChannelIcon({ type }: { type: ActiveChannel['type'] }) {
    const className = 'size-4 text-muted-foreground';

    if (type === 'private') {
        return <Lock className={className} />;
    }

    if (type === 'dm') {
        return <MessageSquare className={className} />;
    }

    return <Hash className={className} />;
}

/**
 * The realtime dot and the member count, as they appear in the channel header.
 * Its own component because it is rendered both inside a button and on its own,
 * and the two must not drift apart.
 */
function PresenceBadge({
    connected,
    memberCount,
}: {
    connected: boolean;
    memberCount: number;
}) {
    return (
        <>
            <span
                className={cn(
                    'size-1.5 rounded-full transition-colors',
                    connected ? 'bg-emerald-500' : 'bg-muted-foreground/40',
                )}
            />
            <Users className="size-3.5" />
            {memberCount}
        </>
    );
}

/**
 * Merge one server-rendered list with what arrived over the socket and what we
 * drew optimistically, keeping the first occurrence of each id. Because the
 * browser mints the ULID, a message it sent comes back over the socket under
 * the same id and simply takes the draft's place.
 */
function mergeById(...sources: ChatMessage[][]): ChatMessage[] {
    const seen = new Set<string>();

    return sources.flat().filter((message) => {
        if (seen.has(message.id)) {
            return false;
        }

        seen.add(message.id);

        return true;
    });
}

/**
 * Apply one emoji toggle to a reaction set, the same way the server will.
 *
 * Counts are derived from the ids rather than tracked alongside them, so the
 * pill can never claim a number its own list doesn't back up.
 */
function toggleEmoji(
    reactions: MessageReaction[],
    emoji: string,
    userId: number,
): MessageReaction[] {
    const existing = reactions.find((reaction) => reaction.emoji === emoji);

    if (!existing) {
        return [...reactions, { emoji, count: 1, userIds: [userId] }];
    }

    const userIds = existing.userIds.includes(userId)
        ? existing.userIds.filter((id) => id !== userId)
        : [...existing.userIds, userId];

    if (userIds.length === 0) {
        return reactions.filter((reaction) => reaction.emoji !== emoji);
    }

    return reactions.map((reaction) =>
        reaction.emoji === emoji
            ? { ...reaction, count: userIds.length, userIds }
            : reaction,
    );
}

/**
 * Mount this with `key={channel.id}` — switching channels should discard every
 * bit of live state rather than carry it across.
 */
export function Conversation({
    workspace,
    channel,
    messages,
    thread,
    pins,
    view,
    tickets,
    ticket,
    scheduled,
    notices,
    channels,
    workspaceTags,
    onSendFiles,
    onSearchChannel,
    onAskSecret,
    onSendSecret,
    onAskPoll,
    sections,
    bookmarkedIds,
    currentUser,
    currentUsername,
    currentUserAvatarUrl,
    currentUserIsGuest = false,
    workspacePanelOpen = false,
    onToggleWorkspacePanel,
}: ConversationProps) {
    const { t } = useTranslate();

    const [pending, setPending] = useState<ChatMessage[]>([]);

    /**
     * The message the composer is currently answering, if any.
     *
     * Component state rather than the URL, unlike an open thread: a half-typed
     * quote is not something you would want to link somebody to, and it should
     * not survive a refresh either.
     */
    const [quoting, setQuoting] = useState<ChatMessage | null>(null);

    const [drafted, setDrafted] = useState<Record<string, MessageReaction[]>>(
        {},
    );
    /**
     * Whether the pin list is open. Component state rather than the URL, unlike
     * a thread or a ticket: the list is the same for everybody in the channel,
     * so a link to the channel already leads to it — there is nothing extra to
     * address.
     */
    /** The message waiting to be sent somewhere else, or null. */
    const [forwarding, setForwarding] = useState<ChatMessage | null>(null);
    const [pinsOpen, setPinsOpen] = useState(false);
    /**
     * Whether the list of what is still to go out is open.
     *
     * Component state rather than the URL, unlike a thread or a ticket: this
     * list is only ever your own, so there is nothing here to link somebody to.
     */
    const [scheduledOpen, setScheduledOpen] = useState(false);
    const [membersOpen, setMembersOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [createTicketOpen, setCreateTicketOpen] = useState(false);
    /**
     * The workflow button being pressed, if one is.
     *
     * One at a time rather than a set: a workflow starts in a single request
     * and the answer comes back as a toast, so the only thing this covers is
     * the moment between the two — long enough to be pressed twice, short
     * enough that nobody presses a second button in it.
     */
    const [pressing, setPressing] = useState<number | null>(null);
    /**
     * The message a ticket is being made out of, if the dialog was opened from
     * one. Component state rather than the URL: a half-filled form is not
     * something you would link somebody to.
     */
    const [promoting, setPromoting] = useState<ChatMessage | null>(null);
    /**
     * Bumped every time the ticket dialog is opened, and used as its key. A
     * fresh mount is what fills the fields with the promoted message, so the
     * dialog needs no effect that writes state when it opens — and never opens
     * holding what somebody typed and abandoned last time.
     */
    const [ticketFormKey, setTicketFormKey] = useState(0);
    /**
     * Messages we have asked the server to delete but not heard back about, and
     * whether each leaves a tombstone. Same shape as the socket's own map.
     */
    const [dropped, setDropped] = useState<Record<string, boolean>>({});
    /**
     * Bodies we have sent an edit for but not heard back about. Cleared once the
     * request finishes: from then on the page props hold the real text, and a
     * stale draft would freeze this message while the rest of the room moves on.
     */
    const [rewritten, setRewritten] = useState<Record<string, string>>({});
    /** Per message, how many reaction requests we have fired. */
    const reactionAttempts = useRef<Record<string, number>>({});
    const {
        live,
        liveReplies,
        replyCounts,
        deleted,
        edits,
        reactions,
        forgetReactions,
        pins: livePins,
        pinStates,
        members,
        typing,
        connected,
        notifyTyping,
    } = useChannelRealtime(channel.id, currentUser);

    // Only where there is a board to keep current. A channel without tickets
    // would otherwise reload its props for an event it can never receive.
    useTicketActivity(channel.id, channel.hasTickets);

    const isReply = (message: ChatMessage) =>
        thread !== null && message.parentId === thread.parent.id;

    /**
     * Overlay whatever we know that the page render didn't. A reply count and a
     * reaction set arrive over the socket as the server's own totals, so they
     * win over the numbers that came with the page — and our own unconfirmed
     * click wins over both, until the request finishes and `drafted` is cleared.
     */
    const withLiveState = (message: ChatMessage): ChatMessage => {
        // Our own unsent rewrite outranks the socket, which outranks the page —
        // the same order as reactions, and for the same reason: what you just
        // typed should be on screen before the server has confirmed it.
        const edited = edits[message.id];
        const draftBody = rewritten[message.id];
        // Absolute, and it may say "no longer pinned" — hence a lookup for the
        // key rather than `?? message.pinnedAt`, which would put a pin back the
        // moment somebody removed it.
        const pinState = pinStates[message.id];

        return {
            ...message,
            pinnedAt: pinState ? pinState.pinnedAt : message.pinnedAt,
            pinnedBy: pinState ? pinState.pinnedBy : message.pinnedBy,
            body: draftBody ?? edited?.body ?? message.body,
            editedAt:
                draftBody !== undefined
                    ? (message.editedAt ?? new Date().toISOString())
                    : (edited?.editedAt ?? message.editedAt),
            replyCount: replyCounts[message.id] ?? message.replyCount,
            reactions:
                drafted[message.id] ??
                reactions[message.id] ??
                message.reactions,
        };
    };

    /**
     * Everything known to be deleted: our own unconfirmed click first, then what
     * the socket has announced. Deleting is one-way, so unlike a reaction set
     * these entries never go stale and never need clearing.
     */
    const deletions = { ...deleted, ...dropped };

    /**
     * Drop deleted messages, except the ones that have to stay: a parent whose
     * replies still hang off it becomes a tombstone, because the link into that
     * thread lives on this row.
     */
    const withoutDeleted = (list: ChatMessage[]): ChatMessage[] =>
        list.flatMap((message) => {
            if (!(message.id in deletions)) {
                return [message];
            }

            return deletions[message.id]
                ? [
                      {
                          ...message,
                          body: '',
                          reactions: [],
                          deletedAt:
                              message.deletedAt ?? new Date().toISOString(),
                      },
                  ]
                : [];
        });

    /**
     * Null once the open thread's parent is gone for good — which only happens
     * after its last reply went too, so there is no thread left to show.
     */
    const threadParent =
        thread === null
            ? null
            : (withoutDeleted([withLiveState(thread.parent)])[0] ?? null);

    const rootMessages = withoutDeleted(
        mergeById(
            messages,
            live,
            pending.filter((draft) => !isReply(draft)),
        ).map(withLiveState),
    );

    const post = useCallback(
        (
            body: string,
            parentId: string | null,
            quoted?: ChatMessage | null,
            files: File[] = [],
        ) => {
            const draft: ChatMessage = {
                id: ulid(),
                parentId,
                // Drawn from what the browser already holds, so the quote block
                // is there in the same frame as the message above it. The
                // server sends the authoritative version moments later.
                quoted: quoted
                    ? {
                          id: quoted.id,
                          author: quoted.author.name,
                          snippet: quoted.body,
                          deleted: false,
                      }
                    : null,
                body,
                createdAt: new Date().toISOString(),
                editedAt: null,
                deletedAt: null,
                replyCount: 0,
                // Nothing is ever posted pinned: pinning is a second, separate
                // act by somebody who runs the channel.
                pinnedAt: null,
                pinnedBy: null,
                // Only a person can be typing into this composer, so the draft
                // is never from a bot.
                author: {
                    ...currentUser,
                    isBot: false,
                    isGuest: currentUserIsGuest,
                    // The page already knows this member's own face.
                    avatarUrl: currentUserAvatarUrl ?? null,
                },
                // A message you type is your own; forwarding goes through its
                // own endpoint and comes back from the server.
                forwardedFrom: null,
                // Looked up after the fact, on a queue: the card arrives with
                // the next render rather than with the message.
                linkPreview: null,
                // Filled in by the server echo: the card is a database lookup,
                // and the browser has not made the transfer row yet.
                transferCard: null,
                secretCard: null,
                pollCard: null,
                formCard: null,
                reactions: [],
                /*
                    Stand-ins pointing at the files still in the browser, so a
                    screenshot appears the moment it is sent rather than when the
                    upload finishes. Replaced by the server's own, which point at
                    the guarded route — these ones only work in this tab.
                */
                attachments: files.map((file, index) => ({
                    // Negative, so it can never collide with a real media id
                    // once the two lists sit side by side during the swap.
                    id: -(index + 1),
                    name: file.name,
                    mimeType: file.type || null,
                    size: file.size,
                    url: URL.createObjectURL(file),
                    previewUrl: null,
                })),
                sentSecretCard: null,
                pending: true,
            };

            setPending((current) => [...current, draft]);

            router.post(
                store.url({ workspace: workspace.slug, channel: channel.id }),
                {
                    id: draft.id,
                    body: draft.body,
                    parent_id: parentId,
                    quoted_message_id: quoted?.id ?? null,
                    // Inertia sends the whole visit as multipart the moment a
                    // File turns up in it, so nothing else has to change.
                    ...(files.length > 0 ? { attachments: files } : {}),
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () => {
                        // Handed back to the browser: an object URL keeps its
                        // file alive until it is revoked, and a channel full of
                        // sent screenshots would hold every one of them for as
                        // long as the tab stays open.
                        draft.attachments.forEach((attachment) =>
                            URL.revokeObjectURL(attachment.url),
                        );

                        setPending((current) =>
                            current.filter((item) => item.id !== draft.id),
                        );
                    },
                },
            );
        },
        [
            channel.id,
            currentUser,
            currentUserAvatarUrl,
            currentUserIsGuest,
            workspace.slug,
        ],
    );

    /**
     * Toggle an emoji on a message. One endpoint handles both directions, so the
     * browser never has to know whether it is adding or removing — it only has
     * to draw the outcome, which is exactly what `toggleEmoji` works out.
     */
    const react = useCallback(
        (message: ChatMessage, emoji: string) => {
            // Click twice quickly and two requests are in flight. Number them
            // per message so the first one to come back doesn't clear the draft
            // the second one drew — which would flash the old pill back.
            const attempt = (reactionAttempts.current[message.id] ?? 0) + 1;
            reactionAttempts.current[message.id] = attempt;

            setDrafted((current) => ({
                ...current,
                [message.id]: toggleEmoji(
                    message.reactions,
                    emoji,
                    currentUser.id,
                ),
            }));

            router.post(
                storeReaction.url({
                    workspace: workspace.slug,
                    channel: channel.id,
                    message: message.id,
                }),
                { emoji },
                {
                    preserveScroll: true,
                    preserveState: true,
                    // The props have just been reloaded, so they are now the
                    // freshest thing we hold about this message. Drop both
                    // overlays: keeping the draft would freeze this pill's
                    // count while everyone else's keeps moving, and keeping the
                    // socket's older payload — the one that announced somebody
                    // else's emoji before we removed ours — would put our own
                    // reaction straight back.
                    onFinish: () => {
                        if (reactionAttempts.current[message.id] !== attempt) {
                            return;
                        }

                        forgetReactions(message.id);
                        setDrafted((current) =>
                            Object.fromEntries(
                                Object.entries(current).filter(
                                    ([id]) => id !== message.id,
                                ),
                            ),
                        );
                    },
                },
            );
        },
        [channel.id, currentUser.id, forgetReactions, workspace.slug],
    );

    /**
     * Delete one of your own messages. Whether a tombstone stays behind is
     * decided by the reply count we already hold, so the row can change the
     * moment you confirm rather than a round trip later.
     */
    const remove = useCallback(
        (message: ChatMessage) => {
            setDropped((current) => ({
                ...current,
                [message.id]: message.replyCount > 0,
            }));

            router.delete(
                destroy.url({
                    workspace: workspace.slug,
                    channel: channel.id,
                    message: message.id,
                }),
                { preserveScroll: true, preserveState: true },
            );
        },
        [channel.id, workspace.slug],
    );

    /**
     * What this member has set aside, as a set the rows can ask.
     *
     * Kept in state rather than read straight off the page props: saving is a
     * click that should land immediately, and waiting for the round trip to
     * colour the icon makes it feel broken.
     */
    const [saved, setSaved] = useState(() => new Set(bookmarkedIds));

    const toggleBookmark = useCallback(
        (message: ChatMessage, bookmarked: boolean) => {
            setSaved((current) => {
                const next = new Set(current);

                if (bookmarked) {
                    next.delete(message.id);
                } else {
                    next.add(message.id);
                }

                return next;
            });

            const target = {
                workspace: workspace.slug,
                channel: channel.id,
                message: message.id,
            };

            const options = { preserveScroll: true, preserveState: true };

            if (bookmarked) {
                router.delete(unbookmark.url(target), options);
            } else {
                router.post(bookmark.url(target), {}, options);
            }
        },
        [channel.id, workspace.slug],
    );

    /**
     * Take one file off a message.
     *
     * Nothing is removed optimistically: the server also deletes the message
     * itself when this was its only content, and guessing which of the two
     * happened is exactly what leaves a row on screen that no longer exists.
     * The reload settles it.
     */
    const removeAttachment = useCallback(
        (message: ChatMessage, attachment: MessageAttachment) => {
            router.delete(
                destroyAttachment.url({
                    workspace: workspace.slug,
                    channel: channel.id,
                    message: message.id,
                    media: attachment.id,
                }),
                { preserveScroll: true },
            );
        },
        [channel.id, workspace.slug],
    );

    /**
     * Rewrite one of your own messages. The new text is shown immediately and
     * only rolled back if the request fails — an edit is a small, deliberate
     * change, so waiting a round trip to see it makes the field feel broken.
     */
    const edit = useCallback(
        (message: ChatMessage, body: string) => {
            setRewritten((current) => ({ ...current, [message.id]: body }));

            router.patch(
                update.url({
                    workspace: workspace.slug,
                    channel: channel.id,
                    message: message.id,
                }),
                { body },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () =>
                        setRewritten((current) =>
                            Object.fromEntries(
                                Object.entries(current).filter(
                                    ([id]) => id !== message.id,
                                ),
                            ),
                        ),
                },
            );
        },
        [channel.id, workspace.slug],
    );

    /**
     * Pin a message to the channel, or take it back down.
     *
     * No optimistic draw, unlike a reaction: this is a deliberate act on
     * somebody else's words that the whole channel sees, and the list it feeds
     * comes back with the props a moment later. Drawing it early would only
     * risk showing a pin the server refused — the ceiling on how many a channel
     * may keep is exactly such a refusal.
     */
    const setPinned = useCallback(
        (messageId: string, pinned: boolean) => {
            const args = {
                workspace: workspace.slug,
                channel: channel.id,
                message: messageId,
            };
            const options = { preserveScroll: true, preserveState: true };

            if (pinned) {
                router.post(pin.url(args), {}, options);
            } else {
                router.delete(unpin.url(args), options);
            }
        },
        [channel.id, workspace.slug],
    );

    const togglePin = useCallback(
        (message: ChatMessage) =>
            setPinned(message.id, message.pinnedAt === null),
        [setPinned],
    );

    const openThread = useCallback(
        (message: ChatMessage) =>
            router.visit(
                show(
                    { workspace: workspace.slug, channel: channel.id },
                    { query: { thread: message.id } },
                ),
                { preserveScroll: true, preserveState: true },
            ),
        [channel.id, workspace.slug],
    );

    const closeThread = useCallback(
        () =>
            router.visit(
                show({ workspace: workspace.slug, channel: channel.id }),
                { preserveScroll: true, preserveState: true },
            ),
        [channel.id, workspace.slug],
    );

    /**
     * Both views and the open ticket live in the URL, the same way an open
     * thread does: a board and a ticket are things you send somebody a link to,
     * and they should survive a refresh.
     */
    /**
     * The number on the Tickets tab: what is still outstanding, counted
     * server-side over every ticket rather than over the rows that happen to be
     * loaded.
     */
    /**
     * The socket's list once it has said anything, the page's list until then.
     * Not a merge: both are complete answers to the same question, and the
     * newer one is simply right.
     */
    const pinList = livePins ?? pins;

    const openTickets = tickets
        ? OPEN_STATUSES.reduce(
              (total, status) => total + (tickets.counts[status] ?? 0),
              0,
          )
        : 0;

    /*
     * The huddle, owned here rather than in the bar that draws it: the button
     * that starts one sits in the header above, and both have to drive the same
     * microphone and the same connections.
     */
    /*
     * Whether the huddle has taken the room the message list usually has.
     * Component state rather than the URL: it is about how you are looking at
     * this channel right now, not about which channel you are in, and there is
     * nothing here to link somebody to.
     */
    const [huddleExpanded, setHuddleExpanded] = useState(false);

    const huddle = useHuddle(
        workspace,
        channel.id,
        currentUser.id,
        channel.huddle,
        channel.iceServers,
    );

    const go = useCallback(
        (query: Record<string, string | number>) =>
            router.visit(
                show(
                    { workspace: workspace.slug, channel: channel.id },
                    { query },
                ),
                { preserveScroll: true, preserveState: true },
            ),
        [channel.id, workspace.slug],
    );

    return (
        <>
            <main className="flex min-w-0 flex-1 flex-col">
                {/*
                    Tighter and horizontally scrollable on a narrow screen. The
                    name keeps its place and truncates; everything to the right
                    of it — the view tabs, the search, the badges, the settings
                    — slides rather than wrapping into a second row, because a
                    header that changes height moves the whole conversation
                    under it.
                */}
                <header className="flex h-14 shrink-0 items-center gap-2 overflow-x-auto border-b px-3 sm:gap-3 sm:px-4 lg:overflow-x-visible">
                    <ChannelIcon type={channel.type} />
                    <div className="min-w-0">
                        <h1 className="truncate text-sm font-semibold">
                            {channel.label}
                        </h1>
                        {channel.topic && (
                            <p className="truncate text-xs text-muted-foreground">
                                {channel.topic}
                            </p>
                        )}
                    </div>

                    {/*
                        Quiet chips next to the name rather than a row of their
                        own: a tag says what kind of channel this is, which is
                        context for the title and not a thing to act on here.
                        The sidebar is where you filter on them.
                    */}
                    {channel.tags.length > 0 && (
                        <div className="hidden shrink-0 items-center gap-1 lg:flex">
                            {channel.tags.map((tag) => (
                                <span
                                    key={tag}
                                    className="rounded-full border px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                                >
                                    {tag}
                                </span>
                            ))}
                        </div>
                    )}

                    {/*
                        Two views of one channel rather than a page of its own:
                        the board needs the same sidebar, the same unread counts
                        and the same live connection. Tabs rather than a fourth
                        panel, which would make the screen unusable below 1400px.
                    */}
                    {channel.hasTickets && tickets && (
                        <nav
                            aria-label={t('conversation.view.label')}
                            className="ml-4 flex items-center gap-1 rounded-md bg-muted/60 p-0.5"
                        >
                            {(
                                [
                                    [
                                        'messages',
                                        t('conversation.view.messages'),
                                        null,
                                    ],
                                    [
                                        'tickets',
                                        t('conversation.view.tickets'),
                                        openTickets,
                                    ],
                                ] as const
                            ).map(([value, label, badge]) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-current={view === value}
                                    onClick={() =>
                                        go(
                                            value === 'tickets'
                                                ? { view: 'tickets' }
                                                : {},
                                        )
                                    }
                                    className={cn(
                                        'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                                        view === value
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                    {badge !== null && badge > 0 && (
                                        <span className="ml-1.5 tabular-nums opacity-70">
                                            {badge}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </nav>
                    )}

                    {/*
                        Straight to searching this conversation, which
                        is where somebody looking for an old message
                        already is. Opens the ordinary palette with
                        "in:dit-kanaal " typed in — not a second search
                        screen, because two search fields that behave
                        differently is how this sort of thing drifts.
                    */}
                    {onSearchChannel && (
                        <button
                            type="button"
                            onClick={onSearchChannel}
                            aria-label={t('conversation.header.search', {
                                channel: channel.label,
                            })}
                            title={t('conversation.header.search', {
                                channel: channel.label,
                            })}
                            className={cn(
                                'rounded-md border p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none',
                                // The first of the right-hand group, so this is
                                // what pushes it over. The huddle button below
                                // must not do it again, or the two would split
                                // the free space between them.
                                'ml-auto',
                            )}
                        >
                            <Search className="size-3.5" />
                        </button>
                    )}

                    {/*
                        Starting a huddle, or walking into the one going on.
                        Here rather than in a bar under the header: a channel
                        where nobody is talking should say nothing at all, and a
                        strip of chrome asking every conversation whether it
                        would like to talk is the sort of thing people learn to
                        look past.
                    */}
                    {channel.canHuddle && (
                        <button
                            type="button"
                            onClick={huddle.join}
                            disabled={
                                huddle.state === 'joining' ||
                                huddle.state === 'in'
                            }
                            aria-label={t('conversation.header.huddle')}
                            title={t('conversation.header.huddle')}
                            className={cn(
                                'rounded-md border p-1.5 transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50',
                                // Lit while somebody is in there, which is the
                                // whole reason to notice this button at all.
                                (channel.huddle?.participants.length ?? 0) > 0
                                    ? 'border-primary/40 text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            <Headphones className="size-3.5" />
                        </button>
                    )}

                    <Tooltip>
                        <TooltipTrigger asChild>
                            {/* Same badge either way, but only a button for
                                somebody who may open the member list. A guest
                                keeps the presence dot and the count — those are
                                about the channel they are already in. */}
                            {channel.canViewMembers ? (
                                <button
                                    type="button"
                                    onClick={() => setMembersOpen(true)}
                                    aria-label={t(
                                        'conversation.header.members',
                                    )}
                                    className="flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    <PresenceBadge
                                        connected={connected}
                                        memberCount={channel.memberCount}
                                    />
                                </button>
                            ) : (
                                <div className="flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs text-muted-foreground">
                                    <PresenceBadge
                                        connected={connected}
                                        memberCount={channel.memberCount}
                                    />
                                </div>
                            )}
                        </TooltipTrigger>
                        <TooltipContent>
                            {!connected
                                ? t('conversation.header.connecting')
                                : `${members.length} nu aanwezig van ${channel.memberCount} leden${channel.canViewMembers ? ' — klik om te beheren' : ''}`}
                        </TooltipContent>
                    </Tooltip>

                    {/*
                        Only when there is something to show: a permanent button
                        leading to an empty list is a button people learn to
                        ignore. It carries the count, because "how many are
                        waiting" is the whole question somebody opens it with.
                    */}
                    {scheduled.length > 0 && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setScheduledOpen((open) => !open)
                                    }
                                    aria-label={t(
                                        'conversation.header.scheduled',
                                    )}
                                    className={cn(
                                        'flex items-center gap-1 rounded-md border px-1.5 py-1 text-xs transition-colors',
                                        scheduled.some(
                                            (row) => row.failedAt !== null,
                                        )
                                            ? 'border-destructive/40 text-destructive'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <CalendarClock className="size-3.5" />
                                    {scheduled.length}
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {scheduled.length === 1
                                    ? '1 bericht staat klaar'
                                    : `${scheduled.length} berichten staan klaar`}
                            </TooltipContent>
                        </Tooltip>
                    )}

                    {/*
                        The way back to the member panel once it has been closed
                        away. Here rather than on the panel itself, because a
                        panel that is gone has nowhere to put its own handle.
                    */}
                    {onToggleWorkspacePanel && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={onToggleWorkspacePanel}
                                    aria-pressed={workspacePanelOpen}
                                    aria-label={t('conversation.members.panel')}
                                    className={cn(
                                        'hidden rounded-md border px-1.5 py-1 text-xs transition-colors lg:flex',
                                        workspacePanelOpen
                                            ? 'border-primary/40 text-primary'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    <Users className="size-3.5" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {workspacePanelOpen
                                    ? t('conversation.members.close')
                                    : t('conversation.members.workspace')}
                            </TooltipContent>
                        </Tooltip>
                    )}

                    {channel.isMember && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router[
                                            channel.isFavorite
                                                ? 'delete'
                                                : 'post'
                                        ](
                                            (channel.isFavorite
                                                ? unfavorite
                                                : favorite
                                            ).url({
                                                workspace: workspace.slug,
                                                channel: channel.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                    aria-pressed={channel.isFavorite}
                                    aria-label={
                                        channel.isFavorite
                                            ? t(
                                                  'conversation.header.unfavorite',
                                              )
                                            : t('conversation.header.favorite')
                                    }
                                    className={cn(
                                        'rounded-md border p-1.5 transition-colors hover:bg-muted hover:text-foreground',
                                        channel.isFavorite
                                            ? 'border-amber-500/40 text-amber-500'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <Star
                                        className={cn(
                                            'size-3.5',
                                            channel.isFavorite &&
                                                'fill-current',
                                        )}
                                    />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {channel.isFavorite
                                    ? t('conversation.header.unfavorite')
                                    : t('conversation.header.favorite')}
                            </TooltipContent>
                        </Tooltip>
                    )}

                    {channel.isMember && (
                        <SectionMenu
                            workspace={workspace}
                            channel={channel}
                            sections={sections}
                        />
                    )}

                    {channel.isMember && (
                        <MuteMenu workspace={workspace} channel={channel} />
                    )}

                    {channel.canManageSettings && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={() => setSettingsOpen(true)}
                                    aria-label={t(
                                        'conversation.header.settings',
                                    )}
                                    className="rounded-md border p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    <Settings className="size-3.5" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {t('conversation.header.settings')}
                            </TooltipContent>
                        </Tooltip>
                    )}
                </header>

                {/*
                    Under the header rather than inside it: the header is
                    already carrying the name, the view tabs, presence and the
                    settings button, and a row of arbitrary many buttons in
                    there would push one of those off the screen. Hidden
                    entirely when there are none, so an ordinary channel does
                    not lose a strip of height to an empty bar.
                */}
                {/*
                    Above the shortcut bar and below the header: a huddle is
                    something happening now, and the row that says so belongs
                    nearer the conversation than the links that are always
                    there.
                */}
                <HuddleBar
                    channel={channel}
                    currentUserId={currentUser.id}
                    controls={huddle}
                    expanded={huddleExpanded}
                    onToggleExpanded={() =>
                        setHuddleExpanded((current) => !current)
                    }
                />

                {channel.links.length > 0 && (
                    <nav
                        aria-label={t('conversation.header.shortcuts')}
                        className="flex shrink-0 flex-wrap items-center gap-1.5 border-b px-4 py-2"
                    >
                        {channel.links.map((link) =>
                            /*
                                Two kinds of button in one bar, told apart by
                                which column is filled. An anchor for the one
                                that leaves and a button for the one that does
                                something here — not one element with a handler
                                that branches, because "opens in a new tab" is
                                something the browser has to know before it is
                                clicked, and middle-clicking a <button> gets
                                nobody anywhere.
                            */
                            link.url !== null ? (
                                <a
                                    key={link.id}
                                    href={link.url}
                                    target="_blank"
                                    // noreferrer alongside noopener: the target
                                    // gets no handle on this window, and no hint
                                    // of which workspace sent the visitor.
                                    rel="noopener noreferrer"
                                    title={link.url}
                                    className={LINK_BUTTON}
                                >
                                    <ExternalLink className="size-3 shrink-0" />
                                    <span className="max-w-40 truncate">
                                        {link.label}
                                    </span>
                                </a>
                            ) : (
                                <button
                                    key={link.id}
                                    type="button"
                                    disabled={pressing === link.id}
                                    onClick={() => {
                                        setPressing(link.id);
                                        router.post(
                                            runLink.url({
                                                workspace: workspace.slug,
                                                channel: channel.id,
                                                link: link.id,
                                            }),
                                            {},
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                onFinish: () =>
                                                    setPressing(null),
                                            },
                                        );
                                    }}
                                    className={cn(
                                        LINK_BUTTON,
                                        'disabled:opacity-60',
                                    )}
                                >
                                    {pressing === link.id ? (
                                        <Spinner className="size-3 shrink-0" />
                                    ) : (
                                        <Zap className="size-3 shrink-0" />
                                    )}
                                    <span className="max-w-40 truncate">
                                        {link.label}
                                    </span>
                                </button>
                            ),
                        )}
                    </nav>
                )}

                {/*
                    The huddle takes the message list's room rather than the
                    whole window: the sidebar stays, so you can see which
                    channels are asking for you and step out with one click.
                */}
                {huddleExpanded && huddle.state === 'in' ? (
                    <HuddleStage
                        currentUserId={currentUser.id}
                        controls={huddle}
                    />
                ) : view === 'tickets' && tickets ? (
                    <TicketBoard
                        board={tickets}
                        activeNumber={ticket?.number ?? null}
                        canCreate={channel.canCreateTicket}
                        onOpen={(number) =>
                            go({ view: 'tickets', ticket: number })
                        }
                        onCreate={() => {
                            setPromoting(null);
                            setTicketFormKey((key) => key + 1);
                            setCreateTicketOpen(true);
                        }}
                    />
                ) : (
                    <>
                        <PinnedBar
                            pins={pinList}
                            onOpen={() => setPinsOpen(true)}
                        />
                        {channel.layout === 'feed' ? (
                            /*
                                Threads stay reachable on an item that already
                                has some, even where answering has since been
                                shut: what was said does not disappear because
                                the channel stopped taking new replies.
                            */
                            <FeedList
                                messages={rootMessages}
                                workspace={workspace}
                                members={channel.members}
                                channels={channels}
                                ticketChannelId={
                                    channel.hasTickets ? channel.id : null
                                }
                                currentUserId={currentUser.id}
                                currentUsername={currentUsername}
                                repliesOpen={channel.repliesOpen}
                                onReact={channel.isMember ? react : undefined}
                                onDelete={remove}
                                onRemoveAttachment={removeAttachment}
                                onEdit={edit}
                                onOpenThread={openThread}
                                onPin={channel.canPin ? togglePin : undefined}
                                canDeleteBotMessages={
                                    channel.canDeleteBotMessages
                                }
                            />
                        ) : (
                            <MessageList
                                messages={rootMessages}
                                workspace={workspace}
                                channelId={channel.id}
                                members={channel.members}
                                channels={channels}
                                ticketChannelId={
                                    channel.hasTickets ? channel.id : null
                                }
                                currentUserId={currentUser.id}
                                currentUsername={currentUsername}
                                onReact={channel.isMember ? react : undefined}
                                onDelete={remove}
                                onRemoveAttachment={removeAttachment}
                                bookmarkedIds={saved}
                                onToggleBookmark={
                                    workspace.features['saved-messages']
                                        ? toggleBookmark
                                        : undefined
                                }
                                onForward={
                                    workspace.features['message-forwarding']
                                        ? setForwarding
                                        : undefined
                                }
                                onEdit={edit}
                                onOpenThread={openThread}
                                onQuote={
                                    channel.canPost ? setQuoting : undefined
                                }
                                onPin={channel.canPin ? togglePin : undefined}
                                canDeleteBotMessages={
                                    channel.canDeleteBotMessages
                                }
                                onPromote={
                                    channel.canCreateTicket
                                        ? (message) => {
                                              setPromoting(message);
                                              setTicketFormKey(
                                                  (key) => key + 1,
                                              );
                                              setCreateTicketOpen(true);
                                          }
                                        : undefined
                                }
                            />
                        )}
                        <NoticeList
                            workspace={workspace}
                            channelId={channel.id}
                            notices={notices}
                        />
                        <TypingIndicator typing={typing} />
                    </>
                )}

                {/*
                    Nothing to write with while the huddle has the room. You are
                    looking at somebody, not typing at them — and the composer
                    would be taking a fifth of the screen to offer something
                    nobody reaches for mid-conversation. It comes back the
                    moment the huddle is made small again.
                */}
                {huddleExpanded && huddle.state === 'in' ? null : view ===
                  'tickets' ? null : !channel.isMember ? (
                    <JoinChannelNotice
                        workspace={workspace}
                        channel={channel}
                    />
                ) : channel.canPost ? (
                    <Composer
                        placeholder={`Bericht aan ${channel.type === 'dm' ? channel.label : '#' + channel.label}`}
                        members={channel.members}
                        channels={channels}
                        workspace={workspace}
                        memberCount={channel.memberCount}
                        attachments={workspace.uploads ?? undefined}
                        /*
                            Built here rather than inside the composer: what is
                            on this list depends on what this member may do in
                            this channel, and those answers already live at this
                            level.

                            These used to be icon buttons beside the field as
                            well. They are only commands now: three features
                            each claiming a button turned the row under the
                            message field into a toolbar nobody could read, and
                            "/" already offers them by name.
                        */
                        commands={[
                            ...(onSendFiles
                                ? [
                                      {
                                          name: 'versturen',
                                          description: t(
                                              'conversation.commands.transfer',
                                          ),
                                          run: onSendFiles,
                                      },
                                  ]
                                : []),
                            ...(onAskSecret
                                ? [
                                      {
                                          name: 'geheim',
                                          description: t(
                                              'conversation.commands.secret_ask',
                                          ),
                                          run: onAskSecret,
                                      },
                                  ]
                                : []),
                            ...(onSendSecret
                                ? [
                                      {
                                          // Next to /geheim rather than further
                                          // down: the two are each other's
                                          // mirror, so the list should read as
                                          // a pair.
                                          name: 'geheim-sturen',
                                          description: t(
                                              'conversation.commands.secret_send',
                                          ),
                                          run: onSendSecret,
                                      },
                                  ]
                                : []),
                            ...(onAskPoll
                                ? [
                                      {
                                          name: 'poll',
                                          description: t(
                                              'conversation.commands.poll',
                                          ),
                                          run: onAskPoll,
                                      },
                                  ]
                                : []),

                            /*
                                And the workspace's own, which are the same
                                thing seen from the other side: these do not
                                open a dialog, they set a workflow going and
                                leave a line only the person who typed them can
                                read. They take an argument, so choosing one
                                writes it into the field rather than firing it.
                            */
                            ...channel.commands.map((command) => ({
                                name: command.name,
                                description: command.description,
                                takesArguments: true,
                                run: (args: string) =>
                                    router.post(
                                        runCommand.url({
                                            workspace: workspace.slug,
                                            channel: channel.id,
                                        }),
                                        {
                                            command: command.name,
                                            arguments: args,
                                        },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
                                    ),
                            })),
                        ]}
                        draftKey={`${workspace.slug}:${channel.id}`}
                        onSend={(body, files) => {
                            post(body, null, quoting, files);
                            setQuoting(null);
                        }}
                        // Only in the channel itself. A thread reply answers
                        // something being said now, so a delay there would land
                        // in a conversation that has moved on.
                        onSchedule={
                            !workspace.features['scheduled-messages']
                                ? undefined
                                : (body, sendAt) => {
                                      setQuoting(null);
                                      router.post(
                                          storeScheduled.url({
                                              workspace: workspace.slug,
                                              channel: channel.id,
                                          }),
                                          // The field hands over a bare wall clock; the
                                          // offset is applied here, where it is known.
                                          {
                                              body,
                                              send_at: fromLocalInput(sendAt),
                                          },
                                          { preserveScroll: true },
                                      );
                                  }
                        }
                        quoting={quoting}
                        onCancelQuote={() => setQuoting(null)}
                        onTyping={notifyTyping}
                    />
                ) : (
                    /*
                        Reacting and answering in a thread are still open here, so
                        this says what you can do rather than only what you can't.
                    */
                    <p className="mx-4 mb-4 rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                        {t('conversation.posting_closed')}
                    </p>
                )}
            </main>

            <ForwardDialog
                workspace={workspace}
                channel={channel}
                channels={channels}
                message={forwarding}
                onClose={() => setForwarding(null)}
            />

            {channel.canManageSettings && (
                <ChannelSettingsDialog
                    workspace={workspace}
                    channel={channel}
                    workspaceTags={workspaceTags}
                    open={settingsOpen}
                    onOpenChange={setSettingsOpen}
                />
            )}

            {channel.canViewMembers && (
                <ChannelMembersDialog
                    workspace={workspace}
                    channel={channel}
                    currentUserId={currentUser.id}
                    open={membersOpen}
                    onOpenChange={setMembersOpen}
                />
            )}

            {channel.canCreateTicket && (
                <CreateTicketDialog
                    key={ticketFormKey}
                    workspace={workspace}
                    // One entry: the channel is already decided here, so the
                    // dialog says where the ticket goes instead of asking.
                    channels={[{ id: channel.id, label: channel.label }]}
                    source={promoting}
                    // A guest does not get the field: their own problem is
                    // always urgent, so the value only means something once one
                    // person weighs all the tickets against each other.
                    canPrioritise={!currentUserIsGuest}
                    open={createTicketOpen}
                    onOpenChange={setCreateTicketOpen}
                />
            )}

            {scheduledOpen && (
                <ScheduledPanel
                    workspace={workspace}
                    channel={channel}
                    messages={scheduled}
                    onClose={() => setScheduledOpen(false)}
                />
            )}

            {view === 'tickets' && ticket && (
                <TicketPanel
                    workspace={workspace}
                    channel={channel}
                    ticket={ticket}
                    onClose={() => go({ view: 'tickets' })}
                />
            )}

            {/*
                One panel at a time: a thread takes the slot back, the same way
                it does from the ticket panel. Nothing is lost — the bar is
                still there to open this again.
            */}
            {view === 'messages' && pinsOpen && !thread && (
                <PinnedPanel
                    pins={pinList}
                    canPin={channel.canPin}
                    onUnpin={(id) => setPinned(id, false)}
                    onClose={() => setPinsOpen(false)}
                />
            )}

            {view === 'messages' && thread && threadParent && (
                <ThreadPanel
                    workspace={workspace}
                    channel={channel}
                    channels={channels}
                    currentUserId={currentUser.id}
                    currentUsername={currentUsername}
                    onReact={channel.isMember ? react : undefined}
                    onDelete={remove}
                    onEdit={edit}
                    parent={threadParent}
                    replies={withoutDeleted(
                        mergeById(
                            thread.replies,
                            liveReplies[thread.parent.id] ?? [],
                            pending.filter(isReply),
                        ).map(withLiveState),
                    )}
                    onClose={closeThread}
                    onReply={(body) => post(body, thread.parent.id)}
                    onTyping={notifyTyping}
                />
            )}
        </>
    );
}
