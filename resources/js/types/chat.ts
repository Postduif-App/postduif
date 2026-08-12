import type { CustomEmojiEntry } from '@/lib/custom-emoji';
import type { Availability } from '@/types/auth';

export type ChannelType = 'public' | 'private' | 'dm';

/** One workspace this member belongs to, as the switcher needs it. */
export interface WorkspaceOption {
    id: number;
    name: string;
    slug: string;
    avatarUrl: string | null;
    /** The one being read now, which the menu marks rather than hides. */
    isCurrent: boolean;
}

/** An announcement waiting to go out, as the broadcast dialog lists it. */
export interface ScheduledBroadcast {
    id: number;
    body: string;
    sendAt: string;
    /** The channels it is meant for, by the name this member sees. */
    channels: string[];
}

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
    /**
     * The roles this member may hand out, worked out by the policy.
     *
     * A list rather than the two words the invite dialog used to know: a
     * workspace writes its own roles, so nothing this side can name them in
     * advance.
     */
    invitableRoles: { id: number; name: string; isExternal: boolean }[];
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
    /**
     * What the message field needs to offer sending files by link, or null when
     * it must not offer it: the workspace has the feature off, or this member
     * may not send.
     */
    transfers: {
        /** Kilobytes for the whole transfer, straight from the workspace. */
        maxKb: number;
        /** The longest a link may be asked to live here. */
        maxDays: number;
    } | null;
    /**
     * Whether this member may ask somebody for a password or a key here. False
     * when the workspace has the feature off, or the role may not.
     */
    secrets: boolean;
    /** Whether this member may put a question to a channel here. */
    polls: boolean;
    /**
     * Whether the rail offers the forms screen. False when the workspace has
     * forms switched off, or this role may not write one — worked out on the
     * server, like secrets above.
     */
    forms: boolean;
    /**
     * Whether the rail offers the contracts screen. False when the workspace
     * has contracts switched off, or this role may not send one — worked out on
     * the server, so the rail cannot lead to a 404 or a 403.
     */
    contracts: boolean;
    /**
     * Whether the rail offers the clock. False for a workspace with
     * tijdregistratie switched off and for a guest, who is here from another
     * company and whose hours are not this workspace's business.
     */
    timeclock: boolean;
    /**
     * Whether the prikbord appears in the rail at all. Already worked out
     * against the feature and this member's role — a guest gets false, and the
     * browser never sees the two halves separately.
     */
    board: boolean;
    uploads: {
        /** Kilobytes, straight from the workspace's own setting. */
        maxKb: number;
        /** A hint for the file dialog; the server decides for real. */
        accept: string;
    } | null;
    /**
     * The pictures this workspace named for itself, for the picker to offer,
     * the composer to suggest and every message to draw. Sent whole with the
     * page: a list that arrived later would show a screenful of chat as bare
     * text for a moment and then rearrange it.
     */
    customEmoji: CustomEmojiEntry[];
}

/**
 * The switches a workspace can throw, under the same names the routes use — so
 * a hidden button and the endpoint that would have refused it are visibly the
 * same feature.
 *
 * In the order of WorkspaceFeature::ALL, which is what BuildChatShell sends.
 * Keeping the two lists in the same order is the only way anyone notices that
 * one of them is missing an entry — which is how five of these came to be
 * absent here while the server had been sending them all along.
 */
export interface WorkspaceFeatures {
    'scheduled-messages': boolean;
    'saved-messages': boolean;
    'message-forwarding': boolean;
    'message-board': boolean;
    tickets: boolean;
    documents: boolean;
    timeclock: boolean;
    polls: boolean;
    forms: boolean;
    /**
     * Whether the rail offers the contracts screen. False when the workspace
     * has contracts switched off, or this role may not send one — worked out on
     * the server, so the rail cannot lead to a 404 or a 403.
     */
    contracts: boolean;
    huddles: boolean;
    webhooks: boolean;
    workflows: boolean;
    'invite-links': boolean;
    transfers: boolean;
    'secret-requests': boolean;
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
     * How many people are talking in this channel right now, zero for the rest.
     * A count rather than names: a sidebar row has a row's width, and what it
     * has to say is that something is happening.
     */
    huddleCount: number;
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
 * Who may write in a channel's documents. Note that this never narrows reading:
 * whoever can see the channel can read its documents.
 */
export type ChannelDocumentPolicy = 'disabled' | 'everyone' | 'members';

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
    /**
     * The last thing this webhook received, to write a path against. Null until
     * something has posted to it; {_truncated: true} when what arrived was too
     * large to be worth keeping.
     */
    lastPayload: Record<string, unknown> | null;
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
    documentPolicy: ChannelDocumentPolicy;
    /** Whether a new or renamed document also says so in the conversation. */
    documentAnnouncements: boolean;
    /**
     * Whether this channel keeps documents at all. Not the same question as
     * documentPolicy !== 'disabled': a DM never does, whatever is stored.
     */
    hasDocuments: boolean;
    /** False for a guest in a members-only channel, and wherever documents are off. */
    canCreateDocument: boolean;
    /**
     * Whether this member may start a message here. Reacting and answering in a
     * thread stay open even when this is false.
     */
    canPost: boolean;
    canManageSettings: boolean;
    /**
     * Whether the bin appears on a message a bot posted.
     *
     * Its own flag rather than something the browser works out: the answer is
     * "you configure this channel, or your role holds the right, or you are a
     * platform moderator", and only the first of those is knowable here. It
     * cannot ride on the message either — that payload is broadcast to everyone
     * at once, and this differs per reader.
     */
    canDeleteBotMessages: boolean;
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
    /** The huddle going on here, or null. Null too where the feature is off. */
    huddle: Huddle | null;
    /**
     * Whether this member may start one or walk into the one that is going.
     * False as well where no STUN server is configured — a button that only
     * connects for two people on the same wifi is worse than no button.
     */
    canHuddle: boolean;
    /**
     * What to hand RTCPeerConnection. Empty for anybody who may not join; the
     * relay credential in it is signed and expires, so it is not shared out.
     */
    iceServers: RTCIceServer[];
    /** Empty for anybody who may not post here. */
    commands: WorkflowCommand[];
    /** Empty for anybody who does not configure this channel. */
    buttonWorkflows: ButtonWorkflow[];
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
/**
 * A button in the bar above the conversation.
 *
 * Exactly one of the two is filled: `url` for a button that goes somewhere
 * outside the app, `workflowId` for one that starts a workflow inside it. The
 * pair is what the bar reads to decide which of the two it is drawing.
 */
export interface ChannelLink {
    id: number;
    label: string;
    url: string | null;
    workflowId: number | null;
    /** What that workflow is called, so the panel can say so without asking. */
    workflowName: string | null;
}

/**
 * Something this member alone was told in a channel.
 *
 * Not a message and never becomes one: it cannot be replied to, reacted to,
 * saved or pinned, and nobody else is sent it. See the ephemeral_notices
 * migration for why the two are kept apart.
 */
export interface EphemeralNotice {
    id: number;
    body: string;
    /** What said it — a workflow's name — or null when nothing signed it. */
    authorName: string | null;
    createdAt: string | null;
}

/**
 * The conversation going on in a channel right now.
 *
 * The same shape whether it arrived with the page or over the socket — see
 * HuddleUpdated::broadcastWith — so nothing here has to reconcile two
 * spellings of it.
 */
export interface Huddle {
    id: number;
    channelId: number;
    live: boolean;
    participants: { id: number; name: string | null }[];
}

/** A workflow the message field answers to, as the palette lists it. */
export interface WorkflowCommand {
    /** Without the slash: the palette and the endpoint both add their own. */
    name: string;
    description: string;
}

/** A workflow a button may be pointed at: on the button trigger, and switched on. */
export interface ButtonWorkflow {
    id: number;
    name: string;
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
    /**
     * What a link to one of our own transfers is carrying, or null when the
     * message holds no such link. Kept apart from linkPreview because it is a
     * different kind of thing: that one is what somebody else's page said about
     * itself, this one came out of our own database.
     */
    transferCard: MessageTransferCard | null;
    /**
     * What a link to one of our own secret requests is asking for, or null when
     * the message holds no such link.
     */
    secretCard: MessageSecretCard | null;
    /** A question put to the channel, with where the votes stand. */
    pollCard: MessagePollCard | null;
    /** A form put to the channel — the questions it asks, and nothing about the answers. */
    formCard: MessageFormCard | null;
    /** A secret put aside for one person: who it is for, never what. */
    sentSecretCard: MessageSentSecretCard | null;
    /** A contract out for signature, and how far it has got — never who. */
    contractCard: MessageContractCard | null;
    /** Set while the message is only in the browser, awaiting the server echo. */
    pending?: boolean;
}

/**
 * A transfer somebody linked to in a conversation.
 *
 * Nothing here was fetched — see TransferCard for why that matters.
 */
export interface MessageTransferCard {
    title: string | null;
    fileCount: number;
    /** Every file together, in bytes. */
    size: number;
    expiresAt: string;
    state: 'usable' | 'expired' | 'revoked' | 'exhausted';
    /** Whether the recipient will be asked for a password. */
    isLocked: boolean;
    url: string;
}

/**
 * A poll somebody put to the channel.
 *
 * The voters are carried along rather than reduced to counts, for two reasons:
 * a vote here is not anonymous, and this payload is broadcast to everybody at
 * once — so "what you chose" cannot be in it, and the browser has to work that
 * out from the list and the user it already knows it is.
 */
export interface MessagePollCard {
    id: string;
    question: string;
    allowsMultiple: boolean;
    isClosed: boolean;
    /** Which kind of closed: somebody stopped it, or the moment passed. */
    state: 'open' | 'closed' | 'expired';
    closesAt: string | null;
    /** Who put the question, so the browser can tell whose poll this is. */
    askedBy: number | null;
    /** People who answered, not ticks cast. */
    voterCount: number;
    options: {
        id: number;
        label: string;
        voters: {
            id: number;
            name: string | null;
            avatarUrl: string | null;
        }[];
    }[];
}

/**
 * A form somebody put in a channel.
 *
 * The opposite decision to the poll above. A poll carries its voters because
 * that is what a poll is for; a form carries nothing whatever about what came
 * back — not the values, not the names, not the number of submissions. This
 * payload is broadcast to everybody in the room at once, and a count is the
 * kind of thing that looks harmless right up until the form is called "Melding
 * ongewenst gedrag". See PresentMessage::formCard.
 */
export interface MessageFormCard {
    id: string;
    title: string;
    description: string | null;
    /** Which kind of shut: somebody stopped it, or the deadline passed. */
    state: 'open' | 'closed' | 'expired';
    closesAt: string | null;
    /** How many questions it asks. Zero means it cannot be filled in yet. */
    fieldCount: number;
    isFillable: boolean;
}

/**
 * A contract somebody linked to in a conversation.
 *
 * Counts rather than names, deliberately. Who has signed and who has not is a
 * list of people at different stages of agreeing to something, and putting it
 * under a message would show the channel exactly who is holding things up.
 */
export interface MessageContractCard {
    id: string;
    title: string;
    signerCount: number;
    signedCount: number;
    /** Null for a contract with no deadline, which is allowed. */
    expiresAt: string | null;
    /**
     * As the card reads it rather than as the column says it: a deadline that
     * passed an hour ago has passed, whether or not the nightly command has
     * been round.
     */
    state: 'draft' | 'sent' | 'completed' | 'cancelled' | 'expired';
    /**
     * The same link for everybody. The server sends a signer who still has
     * something to do to their own page and everybody else to the contract — it
     * cannot be decided here, because this card is broadcast to the whole
     * channel at once.
     */
    url: string;
}

/**
 * A request for secrets somebody linked to in a conversation.
 *
 * Counts only: which key was answered by whom is nobody else's business, and a
 * value never leaves the requester's own screen.
 */
export interface MessageSecretCard {
    id: string;
    title: string;
    keyCount: number;
    answeredCount: number;
    expiresAt: string;
    state: 'open' | 'expired' | 'revoked';
    /**
     * The same link for everybody. The server sends the person who asked on to
     * the answers and everybody else to the form — it cannot be decided here,
     * because this card is broadcast to the whole channel at once.
     */
    url: string;
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
    /** Whether this member asked to stop hearing about it in their inbox. */
    muted: boolean;
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

/** Which of the three views a channel is showing. */
export type ChannelView = 'messages' | 'tickets' | 'documents';

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
          /**
           * Files hung on this comment. Empty for a withdrawn one: taking the
           * words back and leaving the screenshot would be half a withdrawal.
           */
          attachments: MessageAttachment[];
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
    /** Narrower than canEdit: the opener may reword a ticket, not remove it. */
    canDelete: boolean;
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

/** Whoever wrote something on the prikbord, or null once they have left. */
export interface BoardPerson {
    id: number;
    name: string;
    avatarUrl: string | null;
}

/**
 * A notice as the board lists it: enough to scan, not enough to read.
 *
 * The body arrives cut down to an excerpt and the replies do not arrive at all
 * — a board with a year of history on it is a list, and dragging every reply
 * into a payload that draws none of them is how it becomes slow to open.
 */
export interface BoardPostSummary {
    id: string;
    title: string;
    excerpt: string;
    /** Null when the person who wrote it has since left the workspace. */
    author: BoardPerson | null;
    pinned: boolean;
    commentCount: number;
    createdAt: string | null;
    /** When it was last corrected, or null — the board says so out loud. */
    editedAt: string | null;
}

export interface BoardComment {
    id: number;
    author: BoardPerson | null;
    body: string;
    createdAt: string | null;
    editedAt: string | null;
    canEdit: boolean;
    canDelete: boolean;
}

/**
 * The notice named by ?post= in the URL, with everything the panel beside the
 * list draws.
 *
 * What a person may do travels per notice and per reply rather than once for
 * the page: "your own" is a different answer for every row, and the browser
 * must not be the place where that gets worked out.
 */
/**
 * One emoji under a notice, with everybody who left it behind it.
 *
 * Counted and grouped by the server, unlike a message reaction: the board is
 * not a channel and the page holds no member list, so there is nothing here to
 * look a user id up in. `mine` travels for the same reason — the payload is
 * built per reader anyway.
 */
export interface BoardReaction {
    emoji: string;
    count: number;
    mine: boolean;
    /** Can be shorter than `count`: whoever has left is not named. */
    names: string[];
}

export interface OpenBoardPost extends BoardPostSummary {
    body: string;
    canEdit: boolean;
    canDelete: boolean;
    canPin: boolean;
    canComment: boolean;
    canReact: boolean;
    reactions: BoardReaction[];
    comments: BoardComment[];
}

/**
 * A secret somebody put aside for one person in this channel.
 *
 * Note what cannot be here, however useful it would be: the secret. The server
 * holds ciphertext it has no key for, so there is nothing to send. `url` is an
 * announcement rather than a way in — the key rides in the fragment of a link
 * only the sender's browser ever had.
 */
export interface MessageSentSecretCard {
    id: string;
    /** Said in the open by the sender, so safe for the whole channel to read. */
    label: string;
    recipientName: string;
    /** Whose it is, so only they are offered the way to withdraw it. */
    senderId: number;
    expiresAt: string;
    revealedAt: string | null;
    state: 'pending' | 'revealed' | 'expired';
    url: string;
}

/**
 * A document document, exactly as the editor hands it over and takes it back.
 *
 * Deliberately opaque. This is Yoopta's own value — a map of block id to block,
 * each block holding Slate nodes — and its shape moves whenever a plugin is
 * added. Nothing outside the editor should be reading into it, so nothing
 * outside the editor is given a type that would let it. Whatever the rest of
 * the application needs to know about a document's contents comes from the
 * flattened text instead.
 */
export type DocumentContent = Record<string, unknown>;

/**
 * A document as the list shows it: everything except the document.
 *
 * The body is deliberately absent. A channel with a dozen documents would ship a
 * dozen JSON trees to draw a list of titles, and the one that is open arrives
 * separately as an OpenDocument.
 */
export interface DocumentSummary {
    id: number;
    /** What people write down, and what the URL carries. */
    number: number;
    title: string;
    /** The first line or so of the document, flattened to plain text. */
    excerpt: string;
    createdBy: string | null;
    /** Who touched it last, falling back to who started it. */
    updatedBy: string | null;
    createdAt: string | null;
    updatedAt: string | null;
}

/** The document named by ?document= in the URL, with its document. */
export interface OpenDocument extends DocumentSummary {
    body: DocumentContent;
    /**
     * What must be sent back when saving. The whole conflict mechanism as far
     * as the browser is concerned: hold on to it, return it, and be told when
     * somebody else got there first.
     */
    version: number;
    canEdit: boolean;
    canDelete: boolean;
}

/**
 * A document as the search palette lists it.
 *
 * Its own shape rather than a SearchHit, and its own section in the palette: a
 * message hit is a moment in a conversation and this is a document somebody
 * still maintains. Folding them into one list would mean ranking those against
 * each other on a scale neither of them is on.
 */
export interface DocumentHit {
    id: number;
    /** What the URL carries, so a hit opens the document rather than the list. */
    number: number;
    title: string;
    /** A window around the match, not the opening line. */
    snippet: string;
    updatedAt: string | null;
    channel: {
        id: number;
        name: string | null;
    };
}
