import {
    Bookmark,
    Bot,
    Forward,
    MessageSquareText,
    Pencil,
    Pin,
    PinOff,
    Quote,
    Ticket as TicketIcon,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { GuestBadge } from '@/components/chat/guest-badge';
import { LinkPreviewCard } from '@/components/chat/link-preview-card';
import { AvailabilityDot, MemberStatus } from '@/components/chat/member-status';
import { MessageAttachments } from '@/components/chat/message-attachments';
import { MessageBody } from '@/components/chat/message-body';
import {
    MessageToolbar,
    messageToolbarButton,
} from '@/components/chat/message-toolbar';
import { ReactionPicker } from '@/components/chat/reaction-picker';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useHoverShortcuts } from '@/hooks/use-hover-shortcuts';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type {
    ChannelMember,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
    MessageAttachment,
    MessageReaction,
    MessageSender,
    QuotedMessage,
} from '@/types/chat';

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

/**
 * Every bot carries a null id, so comparing ids alone would fold two different
 * webhooks into one block. Bots are told apart by the name they post under.
 */
function senderKey(sender: MessageSender): string {
    return sender.isBot ? `bot:${sender.name}` : `member:${sender.id}`;
}

function shouldGroup(
    message: ChatMessage,
    previous: ChatMessage | undefined,
): boolean {
    if (!previous || senderKey(previous.author) !== senderKey(message.author)) {
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

const NAME_LIST_FORMAT = new Intl.ListFormat('nl-NL', {
    type: 'conjunction',
});

/**
 * "Jij en Anna reageerden met 👍".
 *
 * The names come from the channel's member list, which the frontend already
 * holds for @-mentions. Anyone who has since left the channel is not in it, so
 * they are counted rather than named — otherwise the tooltip would name fewer
 * people than the pill counts.
 */
function reactionLabel(
    reaction: MessageReaction,
    members: ChannelMember[],
    currentUserId: number,
): string {
    const named = reaction.userIds
        .filter((id) => id !== currentUserId)
        .map((id) => members.find((member) => member.id === id)?.name)
        .filter((name): name is string => name !== undefined);

    const names = reaction.userIds.includes(currentUserId)
        ? ['Jij', ...named]
        : named;

    const unnamed = reaction.userIds.length - names.length;

    if (unnamed > 0) {
        names.push(unnamed === 1 ? 'iemand anders' : `${unnamed} anderen`);
    }

    // Agreement follows the number of reactors, not the number of list items:
    // "3 anderen" is one item but still takes the plural.
    return `${NAME_LIST_FORMAT.format(names)} ${
        reaction.userIds.length === 1 ? 'reageerde' : 'reageerden'
    } met ${reaction.emoji}`;
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

/**
 * Bring the quoted message into view and mark it briefly.
 *
 * The original may be older than the fifty messages the page holds, in which
 * case there is nothing to scroll to. False says exactly that, so a caller who
 * can explain it — the pin panel does — is able to.
 */
export function jumpToMessage(id: string): boolean {
    const target = document.getElementById(`message-${id}`);

    if (!target) {
        return false;
    }

    target.scrollIntoView({ block: 'center', behavior: 'smooth' });

    // A flash rather than a lasting highlight: it answers "which one" and then
    // gets out of the way.
    target.classList.add('ring-2', 'ring-primary/60');
    window.setTimeout(
        () => target.classList.remove('ring-2', 'ring-primary/60'),
        1500,
    );

    return true;
}

/**
 * The quoted message above a reply.
 *
 * Deliberately quiet — smaller and dimmer than the reply itself. It is context
 * for what follows, and a quote that shouted as loudly as the answer would make
 * the channel read twice as long as it is.
 */
function QuoteBlock({ quoted }: { quoted: QuotedMessage }) {
    return (
        <button
            type="button"
            onClick={() => jumpToMessage(quoted.id)}
            className="mb-1 flex w-full max-w-prose items-start gap-2 rounded border-l-2 border-primary/40 bg-muted/40 px-2 py-1 text-left text-xs text-muted-foreground transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:outline-none"
        >
            <span className="font-medium text-foreground/70">
                {quoted.author}
            </span>
            <span className="line-clamp-2 min-w-0 flex-1">
                {quoted.deleted ? (
                    <span className="italic">Dit bericht is verwijderd</span>
                ) : (
                    quoted.snippet
                )}
            </span>
        </button>
    );
}

/**
 * The message, turned into a field in place.
 *
 * Deliberately not the Composer: that one carries mention pickers, a quote
 * block, typing whispers and a send button, none of which belong in a
 * three-word correction. What it does share is the keyboard — Enter saves,
 * Shift+Enter breaks a line — because two fields in one screen that answer the
 * same key differently is the kind of thing you only notice by losing text.
 */
function MessageEditor({
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

    // Focus with the caret at the end rather than in front of the text: an edit
    // starts from what is there, and selecting it all invites replacing it.
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

        // An empty message is not an edit but a deletion, and deleting has its
        // own button and its own confirmation.
        if (trimmed !== '') {
            onSave(trimmed);
        }
    };

    return (
        <div className="mt-0.5">
            <textarea
                ref={ref}
                value={draft}
                rows={1}
                maxLength={4000}
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

                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        save();
                    }
                }}
                className="w-full resize-none rounded-md border bg-background px-3 py-2 text-sm leading-relaxed focus-visible:ring-2 focus-visible:outline-none"
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

/**
 * The reaction pills under a message.
 *
 * Its own component because the feed draws the same row: two hand-maintained
 * copies of "whose pill is this" and "what does the tooltip say" would start to
 * differ the first time either is touched — the same reason ChoiceGroup was
 * pulled out of the settings dialog.
 */
export function MessageReactions({
    message,
    members,
    currentUserId,
    onReact,
}: {
    message: ChatMessage;
    members: ChannelMember[];
    currentUserId: number;
    /** Omitted where reacting is not allowed, which also disables the pills. */
    onReact?: (message: ChatMessage, emoji: string) => void;
}) {
    if (message.reactions.length === 0) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {message.reactions.map((reaction) => {
                // Whose pill this is cannot come from the server: one reaction
                // set is broadcast to the whole channel.
                const reacted = reaction.userIds.includes(currentUserId);

                return (
                    <Tooltip key={reaction.emoji}>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                disabled={!onReact}
                                onClick={() =>
                                    onReact?.(message, reaction.emoji)
                                }
                                className={cn(
                                    'flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                    reacted
                                        ? 'border-primary/40 bg-primary/10'
                                        : 'bg-muted/60',
                                    onReact && 'hover:border-primary/40',
                                )}
                            >
                                <span>{reaction.emoji}</span>
                                <span className="text-muted-foreground">
                                    {reaction.count}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {reactionLabel(reaction, members, currentUserId)}
                            {onReact && (
                                <span className="text-muted-foreground">
                                    {reacted
                                        ? ' — klik om weg te halen'
                                        : ' — klik om mee te doen'}
                                </span>
                            )}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </div>
    );
}

function MessageRow({
    message,
    grouped,
    workspace,
    members,
    channels,
    ticketChannelId,
    currentUserId,
    currentUsername,
    onReact,
    onDelete,
    onRemoveAttachment,
    onEdit,
    onOpenThread,
    onQuote,
    onPromote,
    onPin,
    bookmarked = false,
    onToggleBookmark,
    onForward,
}: {
    message: ChatMessage;
    grouped: boolean;
    workspace: ChatWorkspace;
    members: ChannelMember[];
    channels: ChannelSummary[];
    ticketChannelId: number | null;
    currentUserId: number;
    currentUsername?: string;
    onReact?: (message: ChatMessage, emoji: string) => void;
    onDelete?: (message: ChatMessage) => void;
    /** Taking one file back, judged by the same rule as deleting the message. */
    onRemoveAttachment?: (
        message: ChatMessage,
        attachment: MessageAttachment,
    ) => void;
    onEdit?: (message: ChatMessage, body: string) => void;
    onOpenThread?: (message: ChatMessage) => void;
    /** Omitted in the thread panel, where a quote has nowhere to land. */
    onQuote?: (message: ChatMessage) => void;
    /** Turning this message into a ticket. Omitted where the channel keeps none. */
    onPromote?: (message: ChatMessage) => void;
    /**
     * Pinning and unpinning, in one callback: which of the two it is follows
     * from the message itself, so nobody has to keep the two in step.
     */
    onPin?: (message: ChatMessage) => void;
    /** Whether this member has set this message aside. Nobody else sees it. */
    bookmarked?: boolean;
    /**
     * Saving and unsaving in one callback, like pinning: which of the two it is
     * follows from `bookmarked`, so nothing has to keep them in step.
     */
    onToggleBookmark?: (message: ChatMessage, bookmarked: boolean) => void;
    /** Carrying it into another conversation. Absent where there is nowhere to. */
    onForward?: (message: ChatMessage) => void;
}) {
    const getInitials = useInitials();
    const [confirming, setConfirming] = useState(false);
    const [editing, setEditing] = useState(false);
    const [hovered, setHovered] = useState(false);

    /**
     * The author's status as it is right now, looked up in the channel's member
     * list rather than read off the message. The message carries what was said;
     * the member list carries who is saying it today.
     */
    const status = members.find((member) => member.id === message.author.id);

    const deleted = message.deletedAt !== null;
    // Your own words are yours; a tombstone has nothing left to remove.
    const canDelete =
        onDelete !== undefined &&
        !deleted &&
        !message.pending &&
        message.author.id === currentUserId;

    // The same rule as deleting, with one exception the server also makes: a bot
    // message has no author to speak for, so nobody may put words in its mouth.
    const canEdit =
        onEdit !== undefined &&
        !deleted &&
        !message.pending &&
        !message.author.isBot &&
        message.author.id === currentUserId;

    /**
     * Dezelfde acties als in de knoppenbalk, maar op de toets waar de muis nu
     * staat: r citeert, t begint een thread, e bewerkt, d verwijdert. Wat je
     * niet mag blijft weg in plaats van te weigeren — d op andermans bericht
     * doet niets, precies zoals de prullenbak daar niet verschijnt.
     */
    const answerable = !deleted && !message.pending;

    useHoverShortcuts(hovered && !editing, {
        r: onQuote && answerable ? () => onQuote(message) : undefined,
        t: onOpenThread && answerable ? () => onOpenThread(message) : undefined,
        e: canEdit ? () => setEditing(true) : undefined,
        d: canDelete ? () => setConfirming(true) : undefined,
    });

    return (
        <div
            // The anchor a quote block scrolls to.
            id={`message-${message.id}`}
            onMouseEnter={() => setHovered(true)}
            onMouseLeave={() => setHovered(false)}
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
                <div className="relative mt-0.5 shrink-0">
                    <Avatar className="size-9 rounded-md">
                        {/*
                            The image sits above the fallback rather than
                            instead of it: Radix draws the initials until the
                            picture has loaded, and keeps them if it never does.
                        */}
                        {message.author.avatarUrl && (
                            <AvatarImage
                                src={message.author.avatarUrl}
                                alt=""
                                className="rounded-md"
                            />
                        )}
                        <AvatarFallback
                            className={cn(
                                'rounded-md text-xs font-semibold',
                                // A bot should not be mistaken for a colleague at a
                                // glance, so it does not get the member avatar.
                                message.author.isBot &&
                                    'bg-muted text-muted-foreground',
                            )}
                        >
                            {message.author.isBot ? (
                                <Bot className="size-4" aria-hidden />
                            ) : (
                                getInitials(message.author.name)
                            )}
                        </AvatarFallback>
                    </Avatar>
                    {status && (
                        <AvailabilityDot
                            availability={status.availability}
                            className="absolute -right-0.5 -bottom-0.5"
                        />
                    )}
                </div>
            )}

            <div className="min-w-0 flex-1">
                {/*
                    Above the name rather than beside it: this says something
                    about the message's place in the channel, not about who
                    wrote it. Small and grey, because the pin bar at the top is
                    what draws attention — down here it only has to explain why
                    this message turns up in that list.
                */}
                {message.pinnedAt && !deleted && (
                    <p className="mb-0.5 flex items-center gap-1 text-[11px] text-muted-foreground">
                        <Pin className="size-3" aria-hidden />
                        {message.pinnedBy
                            ? `Vastgepind door ${message.pinnedBy}`
                            : 'Vastgepind'}
                    </p>
                )}

                {!grouped && (
                    <div className="flex items-baseline gap-2">
                        <span className="text-sm font-semibold">
                            {message.author.name}
                        </span>
                        {message.author.isBot && (
                            <span className="rounded-sm bg-muted px-1 py-px text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                Bot
                            </span>
                        )}
                        {message.author.isGuest && <GuestBadge />}
                        {status && (
                            <MemberStatus
                                emoji={status.statusEmoji}
                                text={status.statusText}
                            />
                        )}
                        <span className="text-xs text-muted-foreground">
                            {message.createdAt
                                ? TIME_FORMAT.format(
                                      new Date(message.createdAt),
                                  )
                                : ''}
                        </span>
                    </div>
                )}

                {message.forwardedFrom && !deleted && (
                    <p className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Forward className="size-3" />
                        Doorgestuurd — oorspronkelijk van{' '}
                        <span className="font-medium text-foreground/70">
                            {message.forwardedFrom}
                        </span>
                    </p>
                )}

                {message.quoted && !deleted && (
                    <QuoteBlock quoted={message.quoted} />
                )}

                {deleted ? (
                    <p className="text-sm text-muted-foreground italic">
                        Dit bericht is verwijderd
                    </p>
                ) : editing ? (
                    <MessageEditor
                        body={message.body}
                        onCancel={() => setEditing(false)}
                        onSave={(body) => {
                            setEditing(false);

                            // Saving the same text is not an edit: it would only
                            // stamp "(bewerkt)" on a message nobody changed.
                            if (body !== message.body) {
                                onEdit?.(message, body);
                            }
                        }}
                    />
                ) : (
                    <p className="text-sm leading-relaxed break-words whitespace-pre-wrap text-foreground/90">
                        <MessageBody
                            body={message.body}
                            workspace={workspace}
                            members={members}
                            channels={channels}
                            ticketChannelId={ticketChannelId}
                            currentUsername={currentUsername}
                        />
                        {message.editedAt && (
                            <span className="ml-1 text-xs text-muted-foreground">
                                (bewerkt)
                            </span>
                        )}
                    </p>
                )}

                {/*
                    Under the words, not beside them: a message may be nothing
                    but a file, and a layout that puts them side by side has to
                    decide what to do with the empty half.
                */}
                {!deleted && message.linkPreview && (
                    <LinkPreviewCard preview={message.linkPreview} />
                )}

                {!deleted && (
                    <MessageAttachments
                        attachments={message.attachments}
                        // The same rule as deleting the message: a file is part
                        // of what somebody said.
                        onRemove={
                            canDelete && onRemoveAttachment
                                ? (attachment) =>
                                      onRemoveAttachment(message, attachment)
                                : undefined
                        }
                    />
                )}

                <MessageReactions
                    message={message}
                    members={members}
                    currentUserId={currentUserId}
                    onReact={onReact}
                />

                {onOpenThread && message.replyCount > 0 && (
                    <button
                        type="button"
                        onClick={() => onOpenThread(message)}
                        className="mt-1 flex items-center gap-1.5 rounded px-1 py-0.5 text-xs font-medium text-primary hover:underline"
                    >
                        <MessageSquareText className="size-3.5" />
                        {message.replyCount}{' '}
                        {message.replyCount === 1 ? 'antwoord' : 'antwoorden'}
                    </button>
                )}
            </div>

            {!message.pending &&
                !deleted &&
                (onReact ||
                    onOpenThread ||
                    onQuote ||
                    onPromote ||
                    onPin ||
                    canEdit) && (
                    <MessageToolbar>
                        {onReact && (
                            <ReactionPicker
                                onSelect={(emoji) => {
                                    // Picking an emoji means "add this one". Only a
                                    // click on the pill itself takes one away, so
                                    // reaching for 👍 twice can't quietly undo it.
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

                        {onQuote && (
                            <button
                                type="button"
                                onClick={() => onQuote(message)}
                                title="Citeren (R)"
                                aria-label="Citeren"
                                className={messageToolbarButton()}
                            >
                                <Quote className="size-3.5" />
                            </button>
                        )}

                        {onPromote && (
                            <button
                                type="button"
                                onClick={() => onPromote(message)}
                                title="Ticket van dit bericht"
                                aria-label="Ticket van dit bericht"
                                className={messageToolbarButton()}
                            >
                                <TicketIcon className="size-3.5" />
                            </button>
                        )}

                        {onForward && !message.pending && !deleted && (
                            <button
                                type="button"
                                onClick={() => onForward(message)}
                                title="Doorsturen naar een ander kanaal"
                                aria-label="Doorsturen naar een ander kanaal"
                                className={messageToolbarButton()}
                            >
                                <Forward className="size-3.5" />
                            </button>
                        )}

                        {onToggleBookmark && !message.pending && (
                            <button
                                type="button"
                                onClick={() =>
                                    onToggleBookmark(message, bookmarked)
                                }
                                title={
                                    bookmarked
                                        ? 'Niet meer bewaren'
                                        : 'Bewaren voor later'
                                }
                                aria-label={
                                    bookmarked
                                        ? 'Niet meer bewaren'
                                        : 'Bewaren voor later'
                                }
                                aria-pressed={bookmarked}
                                className={messageToolbarButton(
                                    bookmarked
                                        ? 'text-amber-500 hover:text-amber-600'
                                        : undefined,
                                )}
                            >
                                <Bookmark
                                    className={cn(
                                        'size-3.5',
                                        bookmarked && 'fill-current',
                                    )}
                                />
                            </button>
                        )}

                        {onPin && (
                            <button
                                type="button"
                                onClick={() => onPin(message)}
                                title={
                                    message.pinnedAt
                                        ? 'Losmaken'
                                        : 'Vastpinnen in dit kanaal'
                                }
                                aria-label={
                                    message.pinnedAt
                                        ? 'Losmaken'
                                        : 'Vastpinnen in dit kanaal'
                                }
                                aria-pressed={message.pinnedAt !== null}
                                className={messageToolbarButton(
                                    message.pinnedAt
                                        ? 'text-primary'
                                        : undefined,
                                )}
                            >
                                {message.pinnedAt ? (
                                    <PinOff className="size-3.5" />
                                ) : (
                                    <Pin className="size-3.5" />
                                )}
                            </button>
                        )}

                        {canEdit && (
                            <button
                                type="button"
                                onClick={() => setEditing(true)}
                                title="Bericht bewerken (E)"
                                aria-label="Bericht bewerken"
                                className={messageToolbarButton()}
                            >
                                <Pencil className="size-3.5" />
                            </button>
                        )}

                        {onOpenThread && (
                            <button
                                type="button"
                                onClick={() => onOpenThread(message)}
                                title="Antwoord in thread (T)"
                                aria-label="Antwoord in thread"
                                className={messageToolbarButton()}
                            >
                                <MessageSquareText className="size-3.5" />
                            </button>
                        )}

                        {canDelete && (
                            <button
                                type="button"
                                onClick={() => setConfirming(true)}
                                title="Bericht verwijderen (D)"
                                aria-label="Bericht verwijderen"
                                className={messageToolbarButton(
                                    'hover:text-destructive',
                                )}
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        )}
                    </MessageToolbar>
                )}

            {confirming && (
                <AlertDialog open onOpenChange={setConfirming}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Dit bericht verwijderen?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {message.replyCount > 0
                                    ? 'De antwoorden in de thread blijven staan; op deze plek komt "Dit bericht is verwijderd".'
                                    : 'Het bericht verdwijnt voor iedereen in dit kanaal. Je kunt dit niet terugdraaien.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Annuleren</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => onDelete?.(message)}
                            >
                                Verwijderen
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}
        </div>
    );
}

export function MessageList({
    messages,
    workspace,
    members,
    channels,
    ticketChannelId = null,
    currentUserId,
    currentUsername,
    onReact,
    onDelete,
    onRemoveAttachment,
    onEdit,
    onOpenThread,
    onQuote,
    onPromote,
    onPin,
    bookmarkedIds,
    onToggleBookmark,
    onForward,
}: {
    messages: ChatMessage[];
    workspace: ChatWorkspace;
    members: ChannelMember[];
    channels: ChannelSummary[];
    /**
     * The channel a bare "#12" in a message points at, or null where the
     * channel keeps no tickets — then such a number stays plain text.
     */
    ticketChannelId?: number | null;
    currentUserId: number;
    currentUsername?: string;
    /** Taking one file off a message, judged as deleting the message is. */
    onRemoveAttachment?: (
        message: ChatMessage,
        attachment: MessageAttachment,
    ) => void;
    /** The messages on this page this member set aside. */
    bookmarkedIds?: Set<string>;
    onToggleBookmark?: (message: ChatMessage, bookmarked: boolean) => void;
    /** Carrying a message into another conversation. */
    onForward?: (message: ChatMessage) => void;
    /** Omitted where reacting is not allowed, which also hides the picker. */
    onReact?: (message: ChatMessage, emoji: string) => void;
    onDelete?: (message: ChatMessage) => void;
    onEdit?: (message: ChatMessage, body: string) => void;
    onOpenThread?: (message: ChatMessage) => void;
    /** Omitted in the thread panel, where a quote has nowhere to land. */
    onQuote?: (message: ChatMessage) => void;
    /** Turning a message into a ticket. Omitted where the channel keeps none. */
    onPromote?: (message: ChatMessage) => void;
    /** Pinning and unpinning. Omitted for anyone who may not manage the channel. */
    onPin?: (message: ChatMessage) => void;
}) {
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
                                workspace={workspace}
                                members={members}
                                channels={channels}
                                ticketChannelId={ticketChannelId}
                                currentUserId={currentUserId}
                                currentUsername={currentUsername}
                                onReact={onReact}
                                onDelete={onDelete}
                                onRemoveAttachment={onRemoveAttachment}
                                bookmarked={bookmarkedIds?.has(message.id)}
                                onToggleBookmark={onToggleBookmark}
                                onForward={onForward}
                                onEdit={onEdit}
                                onOpenThread={onOpenThread}
                                onQuote={onQuote}
                                onPromote={onPromote}
                                onPin={onPin}
                            />
                        </div>
                    );
                })}
                <div ref={bottomRef} />
            </div>
        </div>
    );
}
