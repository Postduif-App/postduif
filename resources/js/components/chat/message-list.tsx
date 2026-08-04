import { Link } from '@inertiajs/react';
import {
    Bookmark,
    Bot,
    Check,
    Copy,
    Forward,
    Link as LinkIcon,
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
import { PollCard } from '@/components/chat/poll-card';
import { ReactionPicker } from '@/components/chat/reaction-picker';
import { SecretCard } from '@/components/chat/secret-card';
import { SentSecretCard } from '@/components/chat/sent-secret-card';
import { TransferCard } from '@/components/chat/transfer-card';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { useConfettiOnView } from '@/hooks/use-confetti-on-view';
import { useFormats } from '@/hooks/use-formats';
import { useHoverShortcuts } from '@/hooks/use-hover-shortcuts';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { isCelebration } from '@/lib/confetti';
import { isEmojiOnly } from '@/lib/emoji-only';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { show as memberProfile } from '@/routes/chat/members';
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
    // Handed in rather than looked up: this is a plain function, and a hook
    // cannot be called from one. Passing them keeps it testable too.
    t: ReturnType<typeof useTranslate>['t'],
    tChoice: ReturnType<typeof useTranslate>['tChoice'],
    names: Intl.ListFormat,
): string {
    const named = reaction.userIds
        .filter((id) => id !== currentUserId)
        .map((id) => members.find((member) => member.id === id)?.name)
        .filter((name): name is string => name !== undefined);

    const namesList = reaction.userIds.includes(currentUserId)
        ? [t('messages.reaction_you'), ...named]
        : named;

    const unnamed = reaction.userIds.length - namesList.length;

    if (unnamed > 0) {
        namesList.push(
            unnamed === 1
                ? t('messages.reaction_someone')
                : t('messages.reaction_others', { count: unnamed }),
        );
    }

    // Agreement follows the number of reactors, not the number of list items:
    // "3 anderen" is one item but still takes the plural.
    return tChoice('messages.reaction', reaction.userIds.length, {
        names: names.format(namesList),
        emoji: reaction.emoji,
    });
}

function dayKey(iso: string | null): string {
    return iso ? new Date(iso).toDateString() : '';
}

function DayDivider({ iso }: { iso: string | null }) {
    const formats = useFormats();

    if (!iso) {
        return null;
    }

    return (
        <div className="my-4 flex items-center gap-3">
            <span className="h-px flex-1 bg-border" />
            <span className="rounded-full border bg-background px-3 py-0.5 text-xs font-medium text-muted-foreground">
                {formats.day.format(new Date(iso))}
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
    const { t } = useTranslate();

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
                    <span className="italic">{t('messages.deleted')}</span>
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
    const { t } = useTranslate();

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
                    {t('messages.editor.save')}
                </button>
                {' · '}
                <button
                    type="button"
                    onClick={onCancel}
                    className="hover:underline"
                >
                    {t('messages.editor.cancel')}
                </button>
                {' · '}
                <kbd className="rounded bg-muted px-1 font-mono">Esc</kbd>{' '}
                {t('messages.editor.escape_hint')}
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
    const { t, tChoice } = useTranslate();
    const formats = useFormats();

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
                            {reactionLabel(
                                reaction,
                                members,
                                currentUserId,
                                t,
                                tChoice,
                                formats.names,
                            )}
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
    channelId,
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
    /** Which conversation this row is in, so a link to it can be built. */
    channelId: number;
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
    const { t } = useTranslate();
    const formats = useFormats();

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

    const [copied, copy] = useClipboard();

    /*
     * Null for a webhook, and narrowed here rather than at the link: a bot's id
     * is null, so checking isBot tells the type checker nothing about the id it
     * would have to pass.
     */
    const authorId = message.author.isBot ? null : message.author.id;

    /*
     * Absolute, because the point of copying it is to paste it somewhere this
     * app is not — a mail, another chat. A path alone would arrive as text
     * nobody can click.
     */
    const permalink = `${window.location.origin}${show.url({
        workspace: workspace.slug,
        channel: channelId,
    })}#message-${message.id}`;
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

    /**
     * Een bericht dat alleen uit 🎉 bestaat is een felicitatie, en die wordt
     * gevierd zodra je hem leest — niet zodra hij binnenkomt. Wie een uur later
     * terugkomt in het kanaal krijgt het feest alsnog, en wie er nooit langs
     * scrollt krijgt het niet.
     */
    const confettiRef = useConfettiOnView<HTMLDivElement>(
        !deleted && isCelebration(message.body),
    );

    return (
        <div
            ref={confettiRef}
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
                        ? formats.time.format(new Date(message.createdAt))
                        : ''}
                </span>
            ) : (
                /*
                 * self-start is what keeps the availability dot on the face.
                 * The row is a flex container with no alignment of its own, so
                 * a flex item stretches to the row's full height — and on a
                 * message of several lines that box is far taller than the
                 * avatar inside it. The dot is positioned against that box, so
                 * without this it drifts further down the longer the message
                 * gets, which is exactly what it was doing.
                 */
                <div className="relative mt-0.5 shrink-0 self-start">
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
                            ? t('messages.pinned_by', {
                                  name: message.pinnedBy,
                              })
                            : t('messages.pinned')}
                    </p>
                )}

                {!grouped && (
                    <div className="flex items-baseline gap-2">
                        {/*
                            A link for a person, plain text for a bot. A webhook
                            has no page to go to — it carries a name it chose
                            for itself and no member behind it — and a name that
                            looks clickable and is not is worse than one that
                            never offered.
                        */}
                        {authorId === null ? (
                            <span className="text-sm font-semibold">
                                {message.author.name}
                            </span>
                        ) : (
                            <Link
                                href={memberProfile({
                                    workspace: workspace.slug,
                                    member: authorId,
                                })}
                                className="text-sm font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
                            >
                                {message.author.name}
                            </Link>
                        )}
                        {message.author.isBot && (
                            <span className="rounded-sm bg-muted px-1 py-px text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('messages.bot')}
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
                                ? formats.time.format(
                                      new Date(message.createdAt),
                                  )
                                : ''}
                        </span>
                    </div>
                )}

                {message.forwardedFrom && !deleted && (
                    <p className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Forward className="size-3" />
                        {t('messages.forwarded_from')}{' '}
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
                        {t('messages.deleted')}
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
                    <p
                        className={cn(
                            'break-words whitespace-pre-wrap text-foreground/90',
                            // A message that is only emoji is the emoji: at
                            // body size it reads as punctuation on an empty
                            // line rather than as the thing somebody sent.
                            isEmojiOnly(message.body)
                                ? 'text-5xl leading-tight'
                                : 'text-sm leading-relaxed',
                        )}
                    >
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
                                ({t('messages.edited')})
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

                {!deleted && message.transferCard && (
                    <TransferCard card={message.transferCard} />
                )}

                {!deleted && message.secretCard && (
                    <SecretCard card={message.secretCard} />
                )}

                {!deleted && message.pollCard && (
                    <PollCard
                        card={message.pollCard}
                        workspaceSlug={workspace.slug}
                        currentUserId={currentUserId}
                    />
                )}

                {!deleted && message.sentSecretCard && (
                    <SentSecretCard
                        card={message.sentSecretCard}
                        workspaceSlug={workspace.slug}
                        currentUserId={currentUserId}
                    />
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

            {/*
                No condition beyond the message being real: copying is offered
                on every message, so the toolbar always has something in it.
            */}
            {!message.pending && !deleted && (
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

                    {/*
                            Two ways to take a message with you. The text is for
                            pasting it somewhere else; the link is for pointing
                            a colleague at it, and it lands on the message
                            rather than at the bottom of the channel — the same
                            anchor the inbox uses.
                        */}
                    <button
                        type="button"
                        onClick={() => void copy(message.body)}
                        title={t('messages.actions.copy_text')}
                        aria-label={t('messages.actions.copy_text')}
                        className={messageToolbarButton()}
                    >
                        {copied === message.body ? (
                            <Check className="size-3.5" />
                        ) : (
                            <Copy className="size-3.5" />
                        )}
                    </button>

                    <button
                        type="button"
                        onClick={() => void copy(permalink)}
                        title={t('messages.actions.copy_link')}
                        aria-label={t('messages.actions.copy_link')}
                        className={messageToolbarButton()}
                    >
                        {copied === permalink ? (
                            <Check className="size-3.5" />
                        ) : (
                            <LinkIcon className="size-3.5" />
                        )}
                    </button>

                    {onQuote && (
                        <button
                            type="button"
                            onClick={() => onQuote(message)}
                            title={t('messages.actions.quote_key')}
                            aria-label={t('messages.actions.quote')}
                            className={messageToolbarButton()}
                        >
                            <Quote className="size-3.5" />
                        </button>
                    )}

                    {onPromote && (
                        <button
                            type="button"
                            onClick={() => onPromote(message)}
                            title={t('messages.actions.ticket')}
                            aria-label={t('messages.actions.ticket')}
                            className={messageToolbarButton()}
                        >
                            <TicketIcon className="size-3.5" />
                        </button>
                    )}

                    {onForward && !message.pending && !deleted && (
                        <button
                            type="button"
                            onClick={() => onForward(message)}
                            title={t('messages.actions.forward')}
                            aria-label={t('messages.actions.forward')}
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
                                    ? t('messages.actions.unsave')
                                    : t('messages.actions.save')
                            }
                            aria-label={
                                bookmarked
                                    ? t('messages.actions.unsave')
                                    : t('messages.actions.save')
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
                                    ? t('messages.actions.unpin')
                                    : t('messages.actions.pin')
                            }
                            aria-label={
                                message.pinnedAt
                                    ? t('messages.actions.unpin')
                                    : t('messages.actions.pin')
                            }
                            aria-pressed={message.pinnedAt !== null}
                            className={messageToolbarButton(
                                message.pinnedAt ? 'text-primary' : undefined,
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
                            title={t('messages.actions.edit_key')}
                            aria-label={t('messages.actions.edit')}
                            className={messageToolbarButton()}
                        >
                            <Pencil className="size-3.5" />
                        </button>
                    )}

                    {onOpenThread && (
                        <button
                            type="button"
                            onClick={() => onOpenThread(message)}
                            title={t('messages.actions.reply_key')}
                            aria-label={t('messages.actions.reply')}
                            className={messageToolbarButton()}
                        >
                            <MessageSquareText className="size-3.5" />
                        </button>
                    )}

                    {canDelete && (
                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            title={t('messages.actions.delete_key')}
                            aria-label={t('messages.actions.delete')}
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
                                {t('messages.delete.question')}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {message.replyCount > 0
                                    ? t('messages.delete.with_replies')
                                    : t('messages.delete.for_everyone')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>
                                {t('messages.delete.cancel')}
                            </AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => onDelete?.(message)}
                            >
                                {t('messages.delete.confirm')}
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
    channelId,
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
    /** Which conversation these rows are in, so a link to one can be built. */
    channelId: number;
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
    const { t } = useTranslate();

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);

    if (messages.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                <MessageSquareText className="size-8 opacity-40" />
                {t('messages.empty')}
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
                                channelId={channelId}
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
