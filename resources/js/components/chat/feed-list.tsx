import {
    Bot,
    MessageSquareText,
    Newspaper,
    Pencil,
    Pin,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { GuestBadge } from '@/components/chat/guest-badge';
import { LinkPreviewCard } from '@/components/chat/link-preview-card';
import { MessageAttachments } from '@/components/chat/message-attachments';
import { MessageBody } from '@/components/chat/message-body';
import { MessageReactions } from '@/components/chat/message-list';
import {
    MessageToolbar,
    messageToolbarButton,
} from '@/components/chat/message-toolbar';
import { ReactionPicker } from '@/components/chat/reaction-picker';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type {
    ChannelMember,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
    MessageAttachment,
} from '@/types/chat';

const MOMENT_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'long',
    timeStyle: 'short',
});

interface FeedListProps {
    messages: ChatMessage[];
    workspace: ChatWorkspace;
    members: ChannelMember[];
    channels: ChannelSummary[];
    /** The channel a bare "#12" points at, or null where it keeps no tickets. */
    ticketChannelId?: number | null;
    currentUserId: number;
    currentUsername?: string;
    /** Whether answering is open here at all; a news channel often wants it shut. */
    repliesOpen: boolean;
    onReact?: (message: ChatMessage, emoji: string) => void;
    onDelete?: (message: ChatMessage) => void;
    /** Taking one file off a message, judged as deleting the message is. */
    onRemoveAttachment?: (
        message: ChatMessage,
        attachment: MessageAttachment,
    ) => void;
    onEdit?: (message: ChatMessage, body: string) => void;
    onOpenThread?: (message: ChatMessage) => void;
    onPin?: (message: ChatMessage) => void;
}

/**
 * A channel that reads as a feed rather than as a conversation.
 *
 * Newest first, unlike the message list: a chat is read from the bottom because
 * you were following along, while a feed is opened to find out what you missed —
 * and the thing you missed is the most recent one. That also means no scroll to
 * the bottom on arrival, and no grouping window: every item is its own piece,
 * even two posted a minute apart by the same person.
 *
 * Deliberately not a MessageList with a flag. Almost nothing survives the
 * change — no avatars in a gutter, no day dividers, no five-minute grouping, no
 * quoting — and a component carrying both layouts would be two components
 * sharing a name. What is shared is shared explicitly: the body renderer, the
 * reaction row and the toolbar.
 */
export function FeedList({
    messages,
    workspace,
    members,
    channels,
    ticketChannelId = null,
    currentUserId,
    currentUsername,
    repliesOpen,
    onReact,
    onDelete,
    onRemoveAttachment,
    onEdit,
    onOpenThread,
    onPin,
}: FeedListProps) {
    if (messages.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                <Newspaper className="size-8 opacity-40" />
                Nog niets geplaatst.
            </div>
        );
    }

    return (
        <div className="flex-1 overflow-y-auto px-4 py-6">
            <div className="mx-auto flex max-w-2xl flex-col gap-6">
                {[...messages].reverse().map((message) => (
                    <FeedItem
                        key={message.id}
                        message={message}
                        workspace={workspace}
                        members={members}
                        channels={channels}
                        ticketChannelId={ticketChannelId}
                        currentUserId={currentUserId}
                        currentUsername={currentUsername}
                        repliesOpen={repliesOpen}
                        onReact={onReact}
                        onDelete={onDelete}
                        onRemoveAttachment={onRemoveAttachment}
                        onEdit={onEdit}
                        onOpenThread={onOpenThread}
                        onPin={onPin}
                    />
                ))}
            </div>
        </div>
    );
}

/**
 * One post: who wrote it, when, and the whole of it.
 *
 * Nothing is truncated. A feed exists for the messages too long to read in a
 * chat line, so a "lees meer" here would hide exactly what the layout was
 * chosen for.
 */
function FeedItem({
    message,
    workspace,
    members,
    channels,
    ticketChannelId,
    currentUserId,
    currentUsername,
    repliesOpen,
    onReact,
    onDelete,
    onRemoveAttachment,
    onEdit,
    onOpenThread,
    onPin,
}: {
    message: ChatMessage;
    workspace: ChatWorkspace;
    members: ChannelMember[];
    channels: ChannelSummary[];
    ticketChannelId: number | null;
    currentUserId: number;
    currentUsername?: string;
    repliesOpen: boolean;
    onReact?: (message: ChatMessage, emoji: string) => void;
    onDelete?: (message: ChatMessage) => void;
    /** Taking one file off a message, judged as deleting the message is. */
    onRemoveAttachment?: (
        message: ChatMessage,
        attachment: MessageAttachment,
    ) => void;
    onEdit?: (message: ChatMessage, body: string) => void;
    onOpenThread?: (message: ChatMessage) => void;
    onPin?: (message: ChatMessage) => void;
}) {
    const getInitials = useInitials();
    const [editing, setEditing] = useState(false);
    const deleted = message.deletedAt !== null;
    const canEdit =
        !deleted &&
        !message.author.isBot &&
        message.author.id === currentUserId;

    return (
        <article
            id={`message-${message.id}`}
            className={cn(
                'group relative rounded-xl border bg-card p-5 transition-shadow',
                message.pinnedAt && 'border-primary/40',
                message.pending && 'opacity-60',
            )}
        >
            <header className="mb-3 flex items-center gap-3">
                <Avatar className="size-9 shrink-0">
                    <AvatarFallback className="text-xs font-semibold">
                        {message.author.isBot ? (
                            <Bot className="size-4" />
                        ) : (
                            getInitials(message.author.name)
                        )}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 text-sm font-semibold">
                        {message.author.name}
                        {message.author.isBot && (
                            <span className="rounded bg-muted px-1 py-0.5 text-[10px] font-medium text-muted-foreground">
                                bot
                            </span>
                        )}
                        {message.author.isGuest && <GuestBadge />}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {message.createdAt
                            ? MOMENT_FORMAT.format(new Date(message.createdAt))
                            : ''}
                        {message.editedAt && ' · bewerkt'}
                    </p>
                </div>

                {message.pinnedAt && (
                    <span
                        title={
                            message.pinnedBy
                                ? `Vastgepind door ${message.pinnedBy}`
                                : 'Vastgepind'
                        }
                        className="ml-auto shrink-0 text-primary"
                    >
                        <Pin className="size-3.5" />
                    </span>
                )}
            </header>

            {deleted ? (
                <p className="text-sm text-muted-foreground italic">
                    Dit bericht is verwijderd
                </p>
            ) : editing ? (
                <FeedEditor
                    body={message.body}
                    onCancel={() => setEditing(false)}
                    onSave={(body) => {
                        setEditing(false);

                        if (body !== message.body) {
                            onEdit?.(message, body);
                        }
                    }}
                />
            ) : (
                /*
                    Larger and looser than a chat line, which is the whole point
                    of the layout: a paragraph set at chat density is a paragraph
                    nobody finishes.
                */
                <div className="text-[0.95rem] leading-7 break-words whitespace-pre-wrap text-foreground/90">
                    <MessageBody
                        body={message.body}
                        workspace={workspace}
                        members={members}
                        channels={channels}
                        ticketChannelId={ticketChannelId}
                        currentUsername={currentUsername}
                    />
                </div>
            )}

            {message.linkPreview && (
                <LinkPreviewCard preview={message.linkPreview} />
            )}

            <MessageAttachments
                attachments={message.attachments}
                onRemove={
                    canEdit && onRemoveAttachment
                        ? (attachment) =>
                              onRemoveAttachment(message, attachment)
                        : undefined
                }
            />

            <MessageReactions
                message={message}
                members={members}
                currentUserId={currentUserId}
                onReact={onReact}
            />

            {/*
                Only where the channel allows answering. With replies shut this
                row disappears entirely rather than showing a count of nothing.
            */}
            {repliesOpen && onOpenThread && !deleted && (
                <button
                    type="button"
                    onClick={() => onOpenThread(message)}
                    className="mt-3 flex items-center gap-1.5 rounded px-1 py-0.5 text-xs font-medium text-primary hover:underline"
                >
                    <MessageSquareText className="size-3.5" />
                    {message.replyCount === 0
                        ? 'Reageren'
                        : `${message.replyCount} ${
                              message.replyCount === 1 ? 'reactie' : 'reacties'
                          }`}
                </button>
            )}

            {!message.pending && !deleted && (onReact || onPin || canEdit) && (
                <MessageToolbar>
                    {onReact && (
                        <ReactionPicker
                            onSelect={(emoji) => {
                                // Picking an emoji means "add this one"; only a
                                // click on the pill itself takes one away.
                                const mine = message.reactions.some(
                                    (reaction) =>
                                        reaction.emoji === emoji &&
                                        reaction.userIds.includes(
                                            currentUserId,
                                        ),
                                );

                                if (!mine) {
                                    onReact(message, emoji);
                                }
                            }}
                        />
                    )}
                    {canEdit && (
                        <button
                            type="button"
                            onClick={() => setEditing(true)}
                            aria-label="Bewerken"
                            className={messageToolbarButton()}
                        >
                            <Pencil className="size-3.5" />
                        </button>
                    )}
                    {onPin && (
                        <button
                            type="button"
                            onClick={() => onPin(message)}
                            aria-label={
                                message.pinnedAt ? 'Losmaken' : 'Vastpinnen'
                            }
                            className={messageToolbarButton()}
                        >
                            <Pin className="size-3.5" />
                        </button>
                    )}
                    {canEdit && onDelete && (
                        <button
                            type="button"
                            onClick={() => onDelete(message)}
                            aria-label="Verwijderen"
                            className={messageToolbarButton()}
                        >
                            <Trash2 className="size-3.5" />
                        </button>
                    )}
                </MessageToolbar>
            )}
        </article>
    );
}

/**
 * A post, turned into a field in place.
 *
 * No Enter-to-save, unlike the chat editor: a feed post runs to paragraphs, and
 * a key that submits halfway through the second one loses the rest.
 */
function FeedEditor({
    body,
    onSave,
    onCancel,
}: {
    body: string;
    onSave: (body: string) => void;
    onCancel: () => void;
}) {
    const [draft, setDraft] = useState(body);
    const ref = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        const textarea = ref.current;

        if (textarea) {
            textarea.focus();
            textarea.setSelectionRange(draft.length, draft.length);
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
        }
        // Mount only: re-running this on every keystroke would fight the caret.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const save = () => {
        const trimmed = draft.trim();

        // An empty post is not an edit but a deletion, and deleting has its own
        // button and its own confirmation.
        if (trimmed !== '') {
            onSave(trimmed);
        }
    };

    return (
        <div>
            <textarea
                ref={ref}
                value={draft}
                rows={4}
                maxLength={4000}
                aria-label="Bericht bewerken"
                onChange={(event) => {
                    setDraft(event.target.value);
                    event.target.style.height = 'auto';
                    event.target.style.height = `${event.target.scrollHeight}px`;
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onCancel();
                    }
                }}
                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-[0.95rem] leading-7 focus-visible:ring-2 focus-visible:outline-none"
            />
            <p className="mt-1 text-xs text-muted-foreground">
                <button
                    type="button"
                    onClick={save}
                    className="font-medium text-primary hover:underline"
                >
                    Opslaan
                </button>
                {' · '}
                <button
                    type="button"
                    onClick={onCancel}
                    className="hover:underline"
                >
                    Annuleren
                </button>
                {' · '}
                <kbd className="rounded bg-muted px-1 font-mono">Esc</kbd>{' '}
                annuleert
            </p>
        </div>
    );
}
