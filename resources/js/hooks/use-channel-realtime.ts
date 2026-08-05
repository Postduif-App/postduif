import { usePresenceChannel } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import type {
    ChatMessage,
    MessageAuthor,
    MessageReaction,
    PinnedMessage,
} from '@/types/chat';

interface MessagePinnedPayload {
    channelId: number;
    messageId: string;
    /** Null when this event announced an unpin. */
    pinnedAt: string | null;
    pinnedBy: string | null;
    /** The channel's whole pin list, not the one that changed. */
    pins: PinnedMessage[];
}

interface ReactionToggledPayload {
    channelId: number;
    messageId: string;
    /** The message's complete reaction set, not the one emoji that changed. */
    reactions: MessageReaction[];
}

interface MessageDeletedPayload {
    channelId: number;
    messageId: string;
    parentId: string | null;
    /** The parent's new total after this reply went; absolute, not a delta. */
    parentReplyCount: number | null;
    /** True when the message stays on screen as a marker for its replies. */
    tombstone: boolean;
}

interface MessageEditedPayload {
    channelId: number;
    /** The whole message as it now reads, not just the new body. */
    message: ChatMessage;
}

interface MessageSentPayload {
    channelId: number;
    parentId: string | null;
    /** The parent's new total after this reply; absolute, not a delta. */
    parentReplyCount: number | null;
    message: ChatMessage;
}

/** A typing indicator is stale this long after the last keystroke. */
const TYPING_TIMEOUT_MS = 4000;
/** Never whisper more often than this while someone holds down a key. */
const TYPING_THROTTLE_MS = 2000;

interface ChannelRealtime {
    /** Root messages that arrived over the socket since this channel opened. */
    live: ChatMessage[];
    /** Thread replies that arrived since this channel opened, keyed by parent. */
    liveReplies: Record<string, ChatMessage[]>;
    /** Authoritative reply totals pushed by the server, keyed by parent id. */
    replyCounts: Record<string, number>;
    /**
     * Messages that have been deleted since this channel opened, keyed by id.
     * The value says whether a tombstone stays behind.
     *
     * Unlike a reaction set this never needs forgetting: deleting is one-way, so
     * an old entry cannot become wrong.
     */
    deleted: Record<string, boolean>;
    /**
     * Messages rewritten since this channel opened, keyed by id.
     *
     * The whole message, because that is what the server sends: an edit changes
     * the body and the edited marker together, and the payload has already been
     * through the same presenter as everything else on screen.
     */
    edits: Record<string, ChatMessage>;
    /** Authoritative reaction sets pushed by the server, keyed by message id. */
    reactions: Record<string, MessageReaction[]>;
    /**
     * The channel's pin list as the server last announced it, or null while it
     * has announced nothing — in which case the page props are still the truth.
     * Null rather than an empty array, because "no pins" is a real answer here.
     */
    pins: PinnedMessage[] | null;
    /**
     * Pin state per message, for the marker on the row itself. Keyed by id, and
     * carrying null when the message was unpinned.
     */
    pinStates: Record<
        string,
        { pinnedAt: string | null; pinnedBy: string | null }
    >;
    /**
     * Forget what the socket said about one message. Call this the moment the
     * page props are known to be fresher — a payload from before your own
     * change would otherwise outrank them.
     */
    forgetReactions: (messageId: string) => void;
    members: MessageAuthor[];
    typing: MessageAuthor[];
    connected: boolean;
    notifyTyping: () => void;
}

/**
 * Subscribe to a channel's presence socket: new messages, who is here, and who
 * is typing.
 *
 * This hook keeps per-channel state, so mount its consumer with a
 * `key={channelId}` to get a clean slate when the member opens another channel.
 *
 * Typing runs over client whispers rather than broadcast events on purpose.
 * A whisper goes straight from browser to Reverb to the other browsers — it
 * never touches PHP, the queue, or the database. Keystrokes are high-frequency
 * and worthless three seconds later, so persisting them would be all cost.
 */
export function useChannelRealtime(
    channelId: number,
    currentUser: MessageAuthor,
): ChannelRealtime {
    const [live, setLive] = useState<ChatMessage[]>([]);
    const [liveReplies, setLiveReplies] = useState<
        Record<string, ChatMessage[]>
    >({});
    const [replyCounts, setReplyCounts] = useState<Record<string, number>>({});
    const [reactions, setReactions] = useState<
        Record<string, MessageReaction[]>
    >({});
    const [pins, setPins] = useState<PinnedMessage[] | null>(null);
    const [pinStates, setPinStates] = useState<
        Record<string, { pinnedAt: string | null; pinnedBy: string | null }>
    >({});
    const [deleted, setDeleted] = useState<Record<string, boolean>>({});
    const [edits, setEdits] = useState<Record<string, ChatMessage>>({});
    const [members, setMembers] = useState<MessageAuthor[]>([]);
    const [typing, setTyping] = useState<MessageAuthor[]>([]);
    const [connected, setConnected] = useState(false);

    const lastWhisperAt = useRef(0);
    const typingTimers = useRef(new Map<number, number>());

    // Depend on the primitives rather than the object: the caller builds
    // `currentUser` inline, so a fresh identity every render would tear down
    // and rebuild the subscription on each keystroke.
    const { id: userId, name: userName } = currentUser;

    const { channel } = usePresenceChannel(`chat.channel.${channelId}`);

    const forgetTyping = useCallback((userId: number) => {
        window.clearTimeout(typingTimers.current.get(userId));
        typingTimers.current.delete(userId);
        setTyping((current) => current.filter((user) => user.id !== userId));
    }, []);

    const forgetReactions = useCallback((messageId: string) => {
        setReactions((current) =>
            current[messageId] === undefined
                ? current
                : Object.fromEntries(
                      Object.entries(current).filter(
                          ([id]) => id !== messageId,
                      ),
                  ),
        );
    }, []);

    useEffect(() => {
        const subscription = channel();

        if (!subscription) {
            return;
        }

        const timers = typingTimers.current;

        subscription
            .here((users: MessageAuthor[]) => {
                setMembers(users);
                setConnected(true);
            })
            .joining((user: MessageAuthor) =>
                setMembers((current) =>
                    current.some((member) => member.id === user.id)
                        ? current
                        : [...current, user],
                ),
            )
            .leaving((user: MessageAuthor) => {
                setMembers((current) =>
                    current.filter((member) => member.id !== user.id),
                );
                forgetTyping(user.id);
            })
            .listen('.message.sent', (payload: MessageSentPayload) => {
                const { parentId, message } = payload;

                if (parentId === null) {
                    setLive((current) =>
                        current.some((existing) => existing.id === message.id)
                            ? current
                            : [...current, message],
                    );
                } else {
                    // A reply belongs to the thread pane, but the channel still
                    // needs the parent's new count for its "N antwoorden" line.
                    setLiveReplies((current) => {
                        const forParent = current[parentId] ?? [];

                        return forParent.some(
                            (existing) => existing.id === message.id,
                        )
                            ? current
                            : {
                                  ...current,
                                  [parentId]: [...forParent, message],
                              };
                    });

                    if (payload.parentReplyCount !== null) {
                        setReplyCounts((current) => ({
                            ...current,
                            [parentId]: payload.parentReplyCount as number,
                        }));
                    }
                }

                // A bot never announced itself as typing, so there is nothing
                // to clear — and it has no id to clear it by.
                if (message.author.id !== null) {
                    forgetTyping(message.author.id);
                }
            })
            .listen('.message.deleted', (payload: MessageDeletedPayload) => {
                setDeleted((current) => ({
                    ...current,
                    [payload.messageId]: payload.tombstone,
                }));

                if (
                    payload.parentId !== null &&
                    payload.parentReplyCount !== null
                ) {
                    setReplyCounts((current) => ({
                        ...current,
                        [payload.parentId as string]:
                            payload.parentReplyCount as number,
                    }));
                }
            })
            .listen('.message.edited', (payload: MessageEditedPayload) => {
                setEdits((current) => ({
                    ...current,
                    [payload.message.id]: payload.message,
                }));
            })
            /*
                A link somebody pasted has been looked up and the card can be
                drawn. The same payload as an edit and the same handling — the
                whole message is replaced — because that is what this is: the
                server's newest idea of what this message looks like. It is a
                separate event only because nothing here was edited.
            */
            .listen(
                '.link-preview.attached',
                (payload: MessageEditedPayload) => {
                    setEdits((current) => ({
                        ...current,
                        [payload.message.id]: payload.message,
                    }));
                },
            )
            .listen('.reaction.toggled', (payload: ReactionToggledPayload) => {
                // The payload is absolute, so it simply replaces what we hold
                // for this message — no merging, no counting.
                setReactions((current) => ({
                    ...current,
                    [payload.messageId]: payload.reactions,
                }));
            })
            .listen('.message.pinned', (payload: MessagePinnedPayload) => {
                // Absolute in both halves: the whole list replaces the whole
                // list, and the one message's state replaces its own. Applying
                // this twice lands in the same place as applying it once.
                setPins(payload.pins);
                setPinStates((current) => ({
                    ...current,
                    [payload.messageId]: {
                        pinnedAt: payload.pinnedAt,
                        pinnedBy: payload.pinnedBy,
                    },
                }));
            })
            .listenForWhisper('typing', (user: MessageAuthor) => {
                if (user.id === userId) {
                    return;
                }

                setTyping((current) =>
                    current.some((typist) => typist.id === user.id)
                        ? current
                        : [...current, user],
                );

                window.clearTimeout(timers.get(user.id));
                timers.set(
                    user.id,
                    window.setTimeout(
                        () => forgetTyping(user.id),
                        TYPING_TIMEOUT_MS,
                    ),
                );
            });

        return () => {
            timers.forEach((timer) => window.clearTimeout(timer));
            timers.clear();
            subscription.stopListening('.message.sent');
            subscription.stopListening('.message.deleted');
            subscription.stopListening('.message.edited');
            subscription.stopListening('.reaction.toggled');
            subscription.stopListening('.message.pinned');
            subscription.stopListeningForWhisper('typing');
        };
    }, [channel, forgetTyping, userId]);

    const notifyTyping = useCallback(() => {
        const now = Date.now();

        if (now - lastWhisperAt.current < TYPING_THROTTLE_MS) {
            return;
        }

        lastWhisperAt.current = now;
        channel()?.whisper('typing', { id: userId, name: userName });
    }, [channel, userId, userName]);

    return {
        live,
        liveReplies,
        replyCounts,
        deleted,
        edits,
        reactions,
        forgetReactions,
        pins,
        pinStates,
        members,
        typing,
        connected,
        notifyTyping,
    };
}
