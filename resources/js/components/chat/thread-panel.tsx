import { X } from 'lucide-react';

import { Composer } from '@/components/chat/composer';
import { MessageList } from '@/components/chat/message-list';
import { Button } from '@/components/ui/button';
import type { ActiveChannel, ChatMessage } from '@/types/chat';

interface ThreadPanelProps {
    channel: ActiveChannel;
    parent: ChatMessage;
    replies: ChatMessage[];
    onClose: () => void;
    onReply: (body: string) => void;
    onTyping: () => void;
}

export function ThreadPanel({
    channel,
    parent,
    replies,
    onClose,
    onReply,
    onTyping,
}: ThreadPanelProps) {
    return (
        <aside className="flex w-[26rem] shrink-0 flex-col border-l">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">Thread</h2>
                    <p className="truncate text-xs text-muted-foreground">
                        {channel.type === 'dm'
                            ? channel.label
                            : `#${channel.label}`}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto"
                    onClick={onClose}
                    aria-label="Thread sluiten"
                >
                    <X className="size-4" />
                </Button>
            </header>

            {/*
                The parent is rendered through the same MessageList as the
                replies, so grouping, day dividers and reactions behave
                identically in both panes rather than drifting apart.
            */}
            <MessageList messages={[parent, ...replies]} />

            <Composer
                placeholder={
                    channel.isMember
                        ? 'Antwoord in thread'
                        : 'Word lid van dit kanaal om te reageren'
                }
                disabled={!channel.isMember}
                onSend={onReply}
                onTyping={onTyping}
            />
        </aside>
    );
}
