import { usePresenceChannel } from '@laravel/echo-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { ChatMessage, MessageAuthor } from '@/types/chat';

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
    const [liveReplies, setLiveReplies] = useState<Record<string, ChatMessage[]>>({});
    const [replyCounts, setReplyCounts] = useState<Record<string, number>>({});
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

                        return forParent.some((existing) => existing.id === message.id)
                            ? current
                            : { ...current, [parentId]: [...forParent, message] };
                    });

                    if (payload.parentReplyCount !== null) {
                        setReplyCounts((current) => ({
                            ...current,
                            [parentId]: payload.parentReplyCount as number,
                        }));
                    }
                }

                forgetTyping(message.author.id);
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

    return { live, liveReplies, replyCounts, members, typing, connected, notifyTyping };
}
