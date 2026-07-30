import { router } from '@inertiajs/react';
import { Hash, Lock, MessageSquare, Users } from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';

import { Composer } from '@/components/chat/composer';
import { MessageList } from '@/components/chat/message-list';
import { TypingIndicator } from '@/components/chat/typing-indicator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useChannelRealtime } from '@/hooks/use-channel-realtime';
import { ulid } from '@/lib/ulid';
import { cn } from '@/lib/utils';
import { store } from '@/routes/chat/messages';
import type {
    ActiveChannel,
    ChatMessage,
    ChatWorkspace,
    MessageAuthor,
} from '@/types/chat';

interface ConversationProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    /** Messages rendered server-side; authoritative for everything up to now. */
    messages: ChatMessage[];
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
 * Mount this with `key={channel.id}` — switching channels should discard every
 * bit of live state rather than carry it across.
 */
export function Conversation({
    workspace,
    channel,
    messages,
    currentUser,
    userMenu,
}: ConversationProps) {
    const [pending, setPending] = useState<ChatMessage[]>([]);
    const { live, members, typing, connected, notifyTyping } =
        useChannelRealtime(channel.id, currentUser);

    // Three sources, one list, deduplicated by id in order of trust: what the
    // server rendered, what the socket delivered, and what we optimistically
    // drew. Because the browser mints the ULID, its own message arrives back
    // over the socket carrying the same id and simply replaces the draft.
    const seen = new Set(messages.map((message) => message.id));
    const fromSocket = live.filter((message) => !seen.has(message.id));
    fromSocket.forEach((message) => seen.add(message.id));
    const drafts = pending.filter((draft) => !seen.has(draft.id));

    const visibleMessages = [...messages, ...fromSocket, ...drafts];

    const send = useCallback(
        (body: string) => {
            const draft: ChatMessage = {
                id: ulid(),
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
                { id: draft.id, body: draft.body },
                {
                    preserveScroll: true,
                    onFinish: () =>
                        setPending((current) =>
                            current.filter((item) => item.id !== draft.id),
                        ),
                },
            );
        },
        [channel.id, currentUser, workspace.slug],
    );

    return (
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
                            {connected ? members.length : channel.memberCount}
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

            <MessageList messages={visibleMessages} />
            <TypingIndicator typing={typing} />

            <Composer
                placeholder={
                    channel.isMember
                        ? `Bericht aan ${channel.type === 'dm' ? channel.label : '#' + channel.label}`
                        : 'Word lid van dit kanaal om te reageren'
                }
                disabled={!channel.isMember}
                onSend={send}
                onTyping={notifyTyping}
            />
        </main>
    );
}
