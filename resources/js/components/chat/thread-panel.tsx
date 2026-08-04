import { X } from 'lucide-react';

import { Composer } from '@/components/chat/composer';
import { MessageList } from '@/components/chat/message-list';
import { Button } from '@/components/ui/button';
import { useTranslate } from '@/hooks/use-translate';
import type {
    ActiveChannel,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
} from '@/types/chat';

interface ThreadPanelProps {
    workspace: ChatWorkspace;
    channel: ActiveChannel;
    channels: ChannelSummary[];
    currentUserId: number;
    currentUsername?: string;
    parent: ChatMessage;
    replies: ChatMessage[];
    onClose: () => void;
    onReact?: (message: ChatMessage, emoji: string) => void;
    onDelete?: (message: ChatMessage) => void;
    onEdit?: (message: ChatMessage, body: string) => void;
    onReply: (body: string) => void;
    onTyping: () => void;
}

export function ThreadPanel({
    workspace,
    channel,
    channels,
    currentUserId,
    currentUsername,
    parent,
    replies,
    onClose,
    onReact,
    onDelete,
    onEdit,
    onReply,
    onTyping,
}: ThreadPanelProps) {
    const { t } = useTranslate();

    return (
        <aside className="flex w-[26rem] shrink-0 flex-col border-l">
            <header className="flex h-14 shrink-0 items-center gap-3 border-b px-4">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">
                        {t('chat_ui.thread.heading')}
                    </h2>
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
                    aria-label={t('chat_ui.thread.close')}
                >
                    <X className="size-4" />
                </Button>
            </header>

            {/*
                The parent is rendered through the same MessageList as the
                replies, so grouping, day dividers and reactions behave
                identically in both panes rather than drifting apart.
            */}
            <MessageList
                messages={[parent, ...replies]}
                workspace={workspace}
                channelId={channel.id}
                members={channel.members}
                channels={channels}
                ticketChannelId={channel.hasTickets ? channel.id : null}
                currentUserId={currentUserId}
                currentUsername={currentUsername}
                onReact={onReact}
                onDelete={onDelete}
                onEdit={onEdit}
            />

            <Composer
                /*
                    canReply already folds in membership, the archive and the
                    channel's own setting — asking each of those here again is
                    how the panel and the server would end up disagreeing. The
                    placeholder still tells the two common cases apart, because
                    "je mag hier niet" without a reason is the kind of message
                    people file a bug about.
                */
                placeholder={
                    !channel.repliesOpen
                        ? t('chat_ui.thread.replies_closed')
                        : channel.isMember
                          ? t('messages.actions.reply')
                          : t('chat_ui.thread.join_first')
                }
                disabled={!channel.canReply}
                members={channel.members}
                channels={channels}
                workspace={workspace}
                memberCount={channel.memberCount}
                // The thread's own key, not the channel's: an answer half typed
                // in a thread has nothing to do with what stands in the field
                // below the conversation.
                draftKey={`${workspace.slug}:${channel.id}:thread:${parent.id}`}
                onSend={onReply}
                onTyping={onTyping}
            />
        </aside>
    );
}
