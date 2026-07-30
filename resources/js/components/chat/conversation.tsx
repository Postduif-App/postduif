import { router } from '@inertiajs/react';
import { Hash, Lock, MessageSquare, Users } from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';

import { Composer } from '@/components/chat/composer';
import { MessageList } from '@/components/chat/message-list';
import { ThreadPanel } from '@/components/chat/thread-panel';
import { TypingIndicator } from '@/components/chat/typing-indicator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useChannelRealtime } from '@/hooks/use-channel-realtime';
import { ulid } from '@/lib/ulid';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { store } from '@/routes/chat/messages';
import type {
    ActiveChannel,
    ChatMessage,
    ChatWorkspace,
    MessageAuthor,
    OpenThread,
} from '@/types/chat';

interface ConversationProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    /** Messages rendered server-side; authoritative for everything up to now. */
    messages: ChatMessage[];
    /** The thread named by ?thread= in the URL, or null. */
    thread: OpenThread | null;
    currentUser: MessageAuthor;
    userMenu: ReactNode;
}

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
 * Mount this with `key={channel.id}` — switching channels should discard every
 * bit of live state rather than carry it across.
 */
export function Conversation({
    workspace,
    channel,
    messages,
    thread,
    currentUser,
    userMenu,
}: ConversationProps) {
    const [pending, setPending] = useState<ChatMessage[]>([]);
    const {
        live,
        liveReplies,
        replyCounts,
        members,
        typing,
        connected,
        notifyTyping,
    } = useChannelRealtime(channel.id, currentUser);

    const isReply = (message: ChatMessage) =>
        thread !== null && message.parentId === thread.parent.id;

    const rootMessages = mergeById(
        messages,
        live,
        pending.filter((draft) => !isReply(draft)),
        // A reply-count arriving over the socket is the server's own total, so
        // it always wins over the number that was rendered with the page.
    ).map((message) =>
        replyCounts[message.id] === undefined
            ? message
            : { ...message, replyCount: replyCounts[message.id] },
    );

    const post = useCallback(
        (body: string, parentId: string | null) => {
            const draft: ChatMessage = {
                id: ulid(),
                parentId,
                body,
                createdAt: new Date().toISOString(),
                editedAt: null,
                replyCount: 0,
                author: currentUser,
                reactions: [],
                pending: true,
            };

            setPending((current) => [...current, draft]);

            router.post(
                store.url({ workspace: workspace.slug, channel: channel.id }),
                { id: draft.id, body: draft.body, parent_id: parentId },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onFinish: () =>
                        setPending((current) =>
                            current.filter((item) => item.id !== draft.id),
                        ),
                },
            );
        },
        [channel.id, currentUser, workspace.slug],
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

    return (
        <>
            <main className="flex min-w-0 flex-1 flex-col">
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
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

                    <Tooltip>
                        <TooltipTrigger asChild>
                            <span className="ml-auto flex items-center gap-1.5 text-xs text-muted-foreground">
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full transition-colors',
                                        connected
                                            ? 'bg-emerald-500'
                                            : 'bg-muted-foreground/40',
                                    )}
                                />
                                <Users className="size-3.5" />
                                {connected
                                    ? members.length
                                    : channel.memberCount}
                            </span>
                        </TooltipTrigger>
                        <TooltipContent>
                            {connected
                                ? `${members.length} nu aanwezig van ${channel.memberCount} leden`
                                : 'Realtime verbinding wordt opgezet…'}
                        </TooltipContent>
                    </Tooltip>

                    {userMenu}
                </header>

                <MessageList
                    messages={rootMessages}
                    onOpenThread={openThread}
                />
                <TypingIndicator typing={typing} />

                <Composer
                    placeholder={
                        channel.isMember
                            ? `Bericht aan ${channel.type === 'dm' ? channel.label : '#' + channel.label}`
                            : 'Word lid van dit kanaal om te reageren'
                    }
                    disabled={!channel.isMember}
                    onSend={(body) => post(body, null)}
                    onTyping={notifyTyping}
                />
            </main>

            {thread && (
                <ThreadPanel
                    channel={channel}
                    parent={thread.parent}
                    replies={mergeById(
                        thread.replies,
                        liveReplies[thread.parent.id] ?? [],
                        pending.filter(isReply),
                    )}
                    onClose={closeThread}
                    onReply={(body) => post(body, thread.parent.id)}
                    onTyping={notifyTyping}
                />
            )}
        </>
    );
}
