import { MessageSquareText } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChatMessage } from '@/types/chat';

const DAY_FORMAT = new Intl.DateTimeFormat('nl-NL', { dateStyle: 'full' });
const TIME_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    hour: '2-digit',
    minute: '2-digit',
});

/**
 * Two messages group under one avatar when the same person posts again within
 * five minutes — the visual rhythm that makes a chat log readable.
 */
const GROUPING_WINDOW_MS = 5 * 60 * 1000;

function shouldGroup(
    message: ChatMessage,
    previous: ChatMessage | undefined,
): boolean {
    if (!previous || previous.author.id !== message.author.id) {
        return false;
    }

    if (!previous.createdAt || !message.createdAt) {
        return false;
    }

    const gap =
        new Date(message.createdAt).getTime() -
        new Date(previous.createdAt).getTime();

    return gap < GROUPING_WINDOW_MS;
}

function dayKey(iso: string | null): string {
    return iso ? new Date(iso).toDateString() : '';
}

function DayDivider({ iso }: { iso: string | null }) {
    if (!iso) {
        return null;
    }

    return (
        <div className="my-4 flex items-center gap-3">
            <span className="h-px flex-1 bg-border" />
            <span className="rounded-full border bg-background px-3 py-0.5 text-xs font-medium text-muted-foreground">
                {DAY_FORMAT.format(new Date(iso))}
            </span>
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}

function MessageRow({
    message,
    grouped,
}: {
    message: ChatMessage;
    grouped: boolean;
}) {
    const getInitials = useInitials();

    return (
        <div
            className={cn(
                'group relative flex gap-3 rounded-md px-3 transition-colors hover:bg-muted/40',
                grouped ? 'py-0.5' : 'mt-3 py-1',
                message.pending && 'opacity-60',
            )}
        >
            {grouped ? (
                <span className="w-9 shrink-0 pt-0.5 text-right text-[10px] text-muted-foreground opacity-0 group-hover:opacity-100">
                    {message.createdAt
                        ? TIME_FORMAT.format(new Date(message.createdAt))
                        : ''}
                </span>
            ) : (
                <Avatar className="mt-0.5 size-9 shrink-0 rounded-md">
                    <AvatarFallback className="rounded-md text-xs font-semibold">
                        {getInitials(message.author.name)}
                    </AvatarFallback>
                </Avatar>
            )}

            <div className="min-w-0 flex-1">
                {!grouped && (
                    <div className="flex items-baseline gap-2">
                        <span className="text-sm font-semibold">
                            {message.author.name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {message.createdAt
                                ? TIME_FORMAT.format(
                                      new Date(message.createdAt),
                                  )
                                : ''}
                        </span>
                    </div>
                )}

                <p className="text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground/90">
                    {message.body}
                    {message.editedAt && (
                        <span className="ml-1 text-xs text-muted-foreground">
                            (bewerkt)
                        </span>
                    )}
                </p>

                {message.reactions.length > 0 && (
                    <div className="mt-1 flex flex-wrap gap-1">
                        {message.reactions.map((reaction) => (
                            <span
                                key={reaction.emoji}
                                className={cn(
                                    'flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs',
                                    reaction.reacted
                                        ? 'border-primary/40 bg-primary/10'
                                        : 'bg-muted/60',
                                )}
                            >
                                <span>{reaction.emoji}</span>
                                <span className="text-muted-foreground">
                                    {reaction.count}
                                </span>
                            </span>
                        ))}
                    </div>
                )}

                {message.replyCount > 0 && (
                    <button
                        type="button"
                        className="mt-1 flex items-center gap-1.5 rounded px-1 py-0.5 text-xs font-medium text-primary hover:underline"
                    >
                        <MessageSquareText className="size-3.5" />
                        {message.replyCount}{' '}
                        {message.replyCount === 1 ? 'antwoord' : 'antwoorden'}
                    </button>
                )}
            </div>
        </div>
    );
}

export function MessageList({ messages }: { messages: ChatMessage[] }) {
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    if (messages.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                <MessageSquareText className="size-8 opacity-40" />
                Nog geen berichten. Begin het gesprek.
            </div>
        );
    }

    return (
        <div className="flex-1 overflow-y-auto px-3 py-4">
            {/*
                A short conversation should sit against the composer rather than
                float at the top of an empty pane, so the inner column grows to
                full height and pushes its content down.
            */}
            <div className="flex min-h-full flex-col justify-end">
                {messages.map((message, index) => {
                    const previous = messages[index - 1];
                    const newDay =
                        dayKey(message.createdAt) !==
                        dayKey(previous?.createdAt ?? null);

                    return (
                        <div key={message.id}>
                            {newDay && <DayDivider iso={message.createdAt} />}
                            <MessageRow
                                message={message}
                                grouped={
                                    !newDay && shouldGroup(message, previous)
                                }
                            />
                        </div>
                    );
                })}
                <div ref={bottomRef} />
            </div>
        </div>
    );
}
