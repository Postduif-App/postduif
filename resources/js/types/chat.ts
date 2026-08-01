import type { Availability } from '@/types/auth';

export type ChannelType = 'public' | 'private' | 'dm';

export interface ChatWorkspace {
    id: number;
    name: string;
    slug: string;
    /** The workspace's logo, or null — then its first letter stands in. */
    avatarUrl: string | null;
    /** Whether this member may use @here and @everyone. */
    canBroadcastMention: boolean;
    canManage: boolean;
    /** Whether this member may bring people in, as a member or as a guest. */
    canInvite: boolean;
    /** False for guests, who are only in the channels they were invited to. */
    canCreateChannel: boolean;
    /** True for guests too: they may write to people from their own channels. */
    canStartDirectMessage: boolean;
    /** False for a guest: addressing several channels at once is not theirs. */
    canBroadcastToChannels: boolean;
    /**
     * Which parts of the product this workspace offers. Separate from the can*
     * flags on purpose: those say what this member may do, these say what
     * exists here at all. A button needs both.
     */
    features: WorkspaceFeatures;
    /**
     * Whether the member list beside the conversation appears. Already worked
     * out against the setting and this member's role — the browser never sees
     * the three states, only the answer.
     */
    showsMemberPanel: boolean;
    /**
     * What the composer may send along with a message, or null when sharing is
     * switched off for this workspace.
     */
    uploads: {
        /** Kilobytes, straight from the workspace's own setting. */
        maxKb: number;
        /** A hint for the file dialog; the server decides for real. */
        accept: string;
    } | null;
}

/**
 * The switches a workspace can throw, under the same names the routes use — so
 * a hidden button and the endpoint that would have refused it are visibly the
 * same feature.
 */
export interface WorkspaceFeatures {
    'scheduled-messages': boolean;
    'saved-messages': boolean;
    'message-forwarding': boolean;
    tickets: boolean;
    webhooks: boolean;
    'invite-links': boolean;
    'ai-access': boolean;
}

/** Handles that address a group rather than a person. */
export const BROADCAST_HANDLES = ['here', 'everyone'] as const;

export interface ChannelSummary {
    id: number;
    type: ChannelType;
    name: string | null;
    label: string;
    isMember: boolean;
    /**
     * When this member's mute runs out: null when the channel is not muted,
     * 'forever' for one with no end, otherwise an ISO moment.
     */
    mutedUntil: string | null;
    /** Whether this member keeps it at the top of their own sidebar. */
    isFavorite: boolean;
    unreadCount: number;
    mentionCount: number;
    /**
     * Tickets still outstanding in this channel, so a customer sees there is
     * something waiting without opening it. Zero wherever the channel keeps no
     * tickets at all.
     */
    openTicketCount: number;
    /**
     * Whether this channel keeps tickets at all. Not the same question as
     * openTicketCount above, which can be zero in a channel that does.
     */
    hasTickets?: boolean;
    /** The labels on this channel; empty for a DM, which never carries any. */
    tags?: string[];
    /**
     * The other person's status, for a one-on-one. Null for a channel and for
     * any conversation with more than one person on the other side — there is
     * no single status to show there.
     */
    status?: {
        emoji: string | null;
        text: string | null;
        availability: Availability;
        /** Whose status this is, so a socket update finds the right row. */
        userId: number;
    } | null;
}

/**
 * A group somebody made in their own sidebar.
 *
 * Channel ids rather than the channels themselves: the rows are already in the
 * channel list, and two copies of an unread count can disagree.
 */
export interface ChannelSection {
    id: number;
    name: string;
    channelIds: number[];
}

/** A channel that was put away, as the sidebar lists it. */
export interface ArchivedChannel {
    id: number;
    label: string;
    archivedAt: string | null;
}

export interface ChannelMember {
    id: number;
    name: string;
    username: string;
    /** Their face, or null when they set none — then initials stand in. */
    avatarUrl: string | null;
    /** Somebody from outside, present only for the channels they were put in. */
    isGuest: boolean;
    /** What they said they are doing. Null when they said nothing. */
    statusEmoji: string | null;
    statusText: string | null;
    availability: Availability;
}

export type ChannelPostingPolicy = 'everyone' | 'admins';

/**
 * Whether a channel keeps tickets, and who may open one. Off by default: a
 * Tickets tab that is empty everywhere teaches people to stop looking at it.
 */
export type ChannelTicketPolicy = 'disabled' | 'everyone' | 'members';

/**
 * An incoming webhook, as the channel settings see it.
 *
 * The URL carries the token, so it is only ever sent to somebody who may manage
 * the channel. Null when the webhook is revoked, or when it predates the token
 * being stored and there is nothing left to rebuild it from.
 */
export interface ChannelWebhook {
    id: number;
    name: string;
    botName: string;
    /**
     * Where in the sender's own JSON the message text sits, in dot notation.
     * Null when it expects the plain {"text": "..."} it always did.
     */
    bodyPath: string | null;
    createdBy: string | null;
    lastUsedAt: string | null;
    revokedAt: string | null;
    url: string | null;
}

export interface ActiveChannel extends ChannelSummary {
    /**
     * How the conversation is drawn. Not the same question as `type`, which
     * says who may see the channel — a feed can be private.
     */
    layout: ChannelLayout;
    topic: string | null;
    memberCount: number;
    members: ChannelMember[];
    postingPolicy: ChannelPostingPolicy;
    /** Whether threads are open here at all — a news feed often wants them shut. */
    repliesOpen: boolean;
    /** Whether this member may answer. False wherever repliesOpen is. */
    canReply: boolean;
    ticketPolicy: ChannelTicketPolicy;
    /** Whether a new or closed ticket also says so in the conversation. */
    ticketAnnouncements: boolean;
    /**
     * Whether the moves in between are announced too. Nested under the setting
     * above: with announcements off, nothing is said at all.
     */
    ticketStatusAnnouncements: boolean;
    /**
     * Whether this channel keeps tickets at all. Not the same question as
     * ticketPolicy !== 'disabled': a DM never does, whatever is stored.
     */
    hasTickets: boolean;
    /** False for a guest in a members-only channel, and wherever tickets are off. */
    canCreateTicket: boolean;
    /**
     * Whether this member may start a message here. Reacting and answering in a
     * thread stay open even when this is false.
     */
    canPost: boolean;
    canManageSettings: boolean;
    /**
     * Whether this member may take the channel away for good. Not the same
     * question as canManageSettings: an archived channel may still be deleted.
     */
    canDelete: boolean;
    /** Whether this member may put the channel away, and take it back out. */
    canArchive: boolean;
    /**
     * Whether this member may pin and unpin. The same people who configure the
     * channel: a pin is the channel intro rather than a personal bookmark.
     */
    canPin: boolean;
    /** False for guests: the member list stays shut for them. */
    canViewMembers: boolean;
    canAddMembers: boolean;
    canLeave: boolean;
    /** The creator; they cannot be removed and cannot leave. */
    createdBy: number | null;
    /** Buttons in the bar above the conversation, in the order they are drawn. */
    links: ChannelLink[];
    /** The labels on this channel. Tags belong to the workspace, not here. */
    tags: string[];
}

export type ChannelLayout = 'chat' | 'feed';

/** A message written now to be said later, as the panel lists it. */
export interface ScheduledMessage {
    id: number;
    body: string;
    sendAt: string;
    /** When it was given up on, or null while it is still waiting. */
    failedAt: string | null;
    failureReason: string | null;
}

/** One button in the bar above a channel, pointing outside the app. */
export interface ChannelLink {
    id: number;
    label: string;
    url: string;
}

/**
 * A member: someone present in the channel, typing, or signed in. Always a
 * real person, which is why the id is never null here.
 */
export interface MessageAuthor {
    id: number;
    name: string;
}

/**
 * Who sent a message. Unlike MessageAuthor this may be a bot, so it is a
 * separate type rather than a widened one — presence and typing lists must
 * stay unable to hold a bot.
 */
export interface MessageSender {
    /** The sender's face, or null. Always null for a bot. */
    avatarUrl: string | null;
    /**
     * Null when a webhook posted the message. Deliberately not a synthetic id:
     * this value gets compared against the signed-in member, and a bot must
     * never come out equal to anyone.
     */
    id: number | null;
    name: string;
    isBot: boolean;
    /** Labelled in the conversation for the same reason a bot is. */
    isGuest: boolean;
}

export interface MessageReaction {
    emoji: string;
    count: number;
    /**
     * Everyone behind this pill. The server cannot say "you reacted" — the same
     * set is broadcast to every subscriber of the channel — so the browser
     * compares these ids against the signed-in member itself.
     */
    userIds: number[];
}

/**
 * The message a reply is quoting, as its quote block shows it.
 *
 * Trimmed to a single level: quoting a quote would otherwise drag a whole chain
 * of older messages into every payload, and only the top one is ever drawn.
 */
export interface QuotedMessage {
    id: string;
    /** A member's name, or the bot name a webhook posted under. */
    author: string;
    /** Empty when the original has since been deleted. */
    snippet: string;
    deleted: boolean;
}

export interface ChatMessage {
    id: string;
    /** Null for a root message; the thread parent for a reply. */
    parentId: string | null;
    /**
     * The older message this one answers, or null. Independent of parentId: a
     * quote stays in the channel, where a thread reply does not.
     */
    quoted: QuotedMessage | null;
    body: string;
    createdAt: string | null;
    editedAt: string | null;
    /** Set on a tombstone: deleted, but kept because replies hang off it. */
    deletedAt: string | null;
    replyCount: number;
    /** When this was pinned to the channel, or null when it is not pinned. */
    pinnedAt: string | null;
    /** Who pinned it. Null when nobody did, and when that member is gone. */
    pinnedBy: string | null;
    author: MessageSender;
    /**
     * Whose words these were, when the message was carried here from another
     * conversation. A name rather than a link: the original may live in a
     * channel this reader is not in.
     */
    forwardedFrom: string | null;
    reactions: MessageReaction[];
    /** Files sent along with it. Empty for a tombstone, like the body. */
    attachments: MessageAttachment[];
    /**
     * What the first link in the message turned out to be, or null — when there
     * is no link, when the workspace does not fetch them, or when the page did
     * not say enough to draw a card.
     */
    linkPreview: MessageLinkPreview | null;
    /** Set while the message is only in the browser, awaiting the server echo. */
    pending?: boolean;
}

/**
 * A file shared in a conversation.
 *
 * Both URLs point at the same guarded route rather than at a disk: attachments
 * live somewhere private, so there is no address that works without the channel
 * being asked first.
 */
/** What a shared link turned out to be. */
export interface MessageLinkPreview {
    url: string;
    title: string;
    description: string | null;
    imageUrl: string | null;
    siteName: string | null;
}

export interface MessageAttachment {
    id: number;
    name: string;
    mimeType: string | null;
    /** Bytes, as stored. */
    size: number;
    url: string;
    /** The smaller copy, for images. Null when there is none to show. */
    previewUrl: string | null;
}

/**
 * A thread with recent activity, as the sidebar lists it.
 *
 * Listed underneath the channel it belongs to rather than in a section of its
 * own: a thread is part of that conversation, and a separate list would name
 * every channel twice.
 */
export interface ActiveThread {
    id: string;
    /** Which channel row it hangs under; the row itself carries the label. */
    channelId: number;
    /** A member's name, or the bot name a webhook posted under. */
    author: string;
    /** Empty when the parent is a tombstone: deleted, but still the way in. */
    snippet: string;
    replyCount: number;
    lastReplyAt: string | null;
}

/**
 * A pinned message as the bar and the panel show it.
 *
 * Deliberately not a ChatMessage: what is pinned may be far older than the fifty
 * messages the page holds, so this list stands on its own rather than pointing
 * into that one.
 */
export interface PinnedMessage {
    id: string;
    author: string;
    snippet: string;
    pinnedAt: string | null;
    pinnedBy: string | null;
}

export interface OpenThread {
    parent: ChatMessage;
    replies: ChatMessage[];
}

/**
 * Where a ticket stands. 'waiting' is the one that earns its place: without it
 * "open" covers both what the customer is waiting on and what waits on them.
 */
export type TicketStatus =
    'open' | 'in_progress' | 'waiting' | 'resolved' | 'closed';

export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';

/** Which of the two views a channel is showing. */
export type ChannelView = 'messages' | 'tickets';

/**
 * Somebody on a ticket. Null where nothing with a face acted: a webhook, or the
 * scheduled reminder, which the timeline draws as "systeem".
 */
export interface TicketPerson {
    id: number;
    name: string;
    isGuest: boolean;
}

/** A ticket as the board lists it. */
export interface TicketSummary {
    id: number;
    /** What people say out loud, and what the URL carries. */
    number: number;
    channelId: number;
    title: string;
    status: TicketStatus;
    priority: TicketPriority;
    opener: TicketPerson | null;
    assignee: TicketPerson | null;
    commentCount: number;
    createdAt: string | null;
    dueAt: string | null;
    closedAt: string | null;
    lastActivityAt: string | null;
}

/**
 * The board of a channel, or null when the channel keeps no tickets at all —
 * which is a different thing from a channel that has none yet.
 */
export interface TicketBoard {
    rows: TicketSummary[];
    /**
     * Counted server-side over every ticket, not over the rows above: a header
     * that tallies only what happens to be loaded starts lying as soon as a
     * channel gets busy.
     */
    counts: Partial<Record<TicketStatus, number>>;
}

/**
 * One entry in a ticket's history: something somebody said, or something that
 * happened. Separate kinds because only a comment can be edited and withdrawn.
 */
export type TicketTimelineEntry =
    | {
          kind: 'comment';
          id: string;
          author: TicketPerson | null;
          body: string;
          deleted: boolean;
          editedAt: string | null;
          createdAt: string | null;
      }
    | {
          kind: 'event';
          id: string;
          author: TicketPerson | null;
          type: string;
          payload: Record<string, string | number | null>;
          createdAt: string | null;
      };

/** Where a ticket was promoted from, kept even after that message is gone. */
export interface TicketSource {
    id: string;
    channelId: number;
    author: string;
    snippet: string;
    deleted: boolean;
}

export interface OpenTicket extends TicketSummary {
    body: string;
    source: TicketSource | null;
    timeline: TicketTimelineEntry[];
    /** Status, priority, assignee, due date — not for guests. */
    canManage: boolean;
    /** Confirming a resolved ticket, or saying it is not fixed after all. */
    canConfirm: boolean;
    canEdit: boolean;
}

export interface SearchHit {
    id: string;
    body: string;
    createdAt: string | null;
    /** A member's name, or the bot name a webhook posted under. */
    author: string;
    authorIsBot: boolean;
    /** The thread this message is a reply in, or null when it sits in the channel itself. */
    threadId: string | null;
    channel: {
        id: number;
        name: string | null;
        type: ChannelType;
    };
}
