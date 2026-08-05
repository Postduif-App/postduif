import {
    CalendarClock,
    Hash,
    Lock,
    Megaphone,
    Mic,
    Paperclip,
    SendHorizonal,
    Slash,
    Square,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';

import { ReactionEmoji } from '@/components/chat/custom-emoji';
import { ReactionPicker } from '@/components/chat/reaction-picker';
import { Button } from '@/components/ui/button';
import { useCustomEmoji } from '@/hooks/use-custom-emoji';
import { useFormats } from '@/hooks/use-formats';
import { useInitials } from '@/hooks/use-initials';
import { useTranslate } from '@/hooks/use-translate';
import { useVoiceRecorder } from '@/hooks/use-voice-recorder';
import {
    readDraft,
    readDraftOnServer,
    saveDraft,
    subscribeToDraft,
} from '@/lib/composer-draft';
import type { ActiveTrigger } from '@/lib/composer-triggers';
import { FRAGMENT, triggerAt } from '@/lib/composer-triggers';
import { EMOJI_GROUPS } from '@/lib/emoji';
import { readableSize } from '@/lib/file-size';
import { cn } from '@/lib/utils';
import type {
    ChannelMember,
    ChannelSummary,
    ChatMessage,
    ChatWorkspace,
} from '@/types/chat';

interface ComposerProps {
    placeholder: string;
    disabled?: boolean;
    members?: ChannelMember[];
    /** Channels the author may link to; the sidebar list is exactly this set. */
    channels?: ChannelSummary[];
    workspace: ChatWorkspace;
    /** How many people @everyone would reach, for the hint in the picker. */
    memberCount?: number;
    /**
     * Which pickers this field offers. Empty where a mention would be a promise
     * nobody keeps: a ticket comment is not a message, so an "@fenna" in one
     * notifies no-one — and a picker that suggests otherwise is worse than no
     * picker at all. The formatting markers work everywhere and stay.
     */
    triggers?: string;
    /**
     * Offering to send this later. Omitted where that makes no sense — a thread
     * reply answers something being said now, and a ticket comment is not a
     * message at all.
     */
    onSchedule?: (body: string, sendAt: string) => void;
    /**
     * Sending files along with it.
     *
     * Absent where a file has nowhere to hang: a ticket comment is not a
     * message, and the workspace may have sharing switched off altogether. The
     * paperclip only appears when this is here, so the composer never offers a
     * thing the server would refuse.
     */
    attachments?: {
        /** Kilobytes, straight from the workspace's own setting. */
        maxKb: number;
        /** For the file dialog's filter — the server decides for real. */
        accept: string;
    };
    /**
     * Sending files by link instead of hanging them on the message.
     *
     * Absent where it cannot be offered: the workspace has the feature off, or
     * this member may not send. Separate from attachments because the two
     * answer different questions — that one is what a message may carry, this
     * one is what to do with the file that will not fit in one.
     */
    /**
     * What "/" offers, or absent for a field that has nothing to offer.
     *
     * The caller decides the list, because what is on it depends on what this
     * member may do here — and those answers already live where the composer is
     * rendered rather than inside it.
     */
    commands?: ComposerCommand[];
    /**
     * Whether the field may offer to ask somebody for a password or a key.
     * A plain boolean, unlike transfers: there are no ceilings to pass on.
     */
    /**
     * Where to keep what was typed but not sent, or absent not to keep it.
     *
     * A string rather than a boolean because it names the conversation: the
     * channel, or the thread inside it. Two composers sharing a key would hand
     * each other their drafts.
     */
    draftKey?: string;
    onSend: (body: string, files: File[]) => void;
    /** The message being answered, shown above the field until it is sent. */
    quoting?: ChatMessage | null;
    onCancelQuote?: () => void;
    /** Called on every keystroke; the hook decides how often to actually emit. */
    onTyping?: () => void;
}

const MAX_ROWS_HEIGHT = 200;

/** What one message carries. The endpoint refuses more — see StoreMessageRequest. */
const MAX_ATTACHMENTS = 10;

/**
 * What the date field opens on: ten minutes from now, in the shape a
 * datetime-local input takes.
 *
 * Local time on purpose — the value is read back as local by the browser and
 * sent as written, so somebody who picks 09:00 means nine in the morning where
 * they are. Built by hand rather than through toISOString(), which converts to
 * UTC and would shift the shown time by the offset.
 */
function defaultSendAt(): string {
    const when = new Date(Date.now() + 10 * 60 * 1000);
    const pad = (value: number) => String(value).padStart(2, '0');

    return (
        `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}` +
        `T${pad(when.getHours())}:${pad(when.getMinutes())}`
    );
}
const MAX_SUGGESTIONS = 6;

/**
 * "@" picks a person, "#" picks a channel. Both behave identically from the
 * typist's side, so they share one picker rather than two that drift apart.
 */
const TRIGGERS = '@#:';

/**
 * How much you have to type after ":" before emoji are offered.
 *
 * Two characters, because one would open the list on every smiley somebody
 * types by hand — ":)" is not a request for a picker.
 */
const EMOJI_QUERY_FLOOR = 2;

/**
 * Something the message field can do instead of sending words.
 *
 * Reached by typing "/" at the very start of an empty message. A command is a
 * thing the browser does — open a dialog, mostly — and never something posted
 * to the server: the field either sends a message or gets out of the way.
 */
export interface ComposerCommand {
    /** Typed after the slash, and what the list filters on. */
    name: string;
    description: string;
    run: () => void;
}

interface Suggestion {
    key: string;
    /** What gets written into the message, without the trigger character. */
    insert: string;
    primary: string;
    secondary?: string;
    icon: 'member' | 'public' | 'private' | 'broadcast' | 'emoji' | 'command';
    /**
     * What the emoji suggestions carry: the trigger goes away with them, unlike
     * an @handle or a #channel, which keep theirs in the message.
     */
    replacesTrigger?: boolean;
    /**
     * What a command does when it is chosen. Present only on commands, and its
     * presence is what tells complete() to run something and empty the field
     * rather than write into it.
     */
    run?: () => void;
}

function SuggestionIcon({
    icon,
    name,
}: {
    icon: Suggestion['icon'];
    name: string;
}) {
    const getInitials = useInitials();

    // The emoji is its own icon; anything drawn around it would be a box around
    // a picture.
    if (icon === 'emoji') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center text-base leading-none">
                {/*
                    A symbol or one of this workspace's own pictures — the row
                    is offered for both, and only the stored string knows which.
                */}
                <ReactionEmoji emoji={name} />
            </span>
        );
    }

    if (icon === 'command') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded bg-primary/10 text-primary">
                <Slash className="size-3" />
            </span>
        );
    }

    if (icon === 'broadcast') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded bg-amber-400/20 text-amber-600 dark:text-amber-400">
                <Megaphone className="size-3" />
            </span>
        );
    }

    if (icon === 'member') {
        return (
            <span className="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold">
                {getInitials(name)}
            </span>
        );
    }

    return (
        <span className="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
            {icon === 'private' ? (
                <Lock className="size-3" />
            ) : (
                <Hash className="size-3" />
            )}
        </span>
    );
}

export function Composer({
    placeholder,
    disabled = false,
    members = [],
    channels = [],
    workspace,
    memberCount = 0,
    triggers = TRIGGERS,
    attachments,
    commands,
    draftKey,
    onSend,
    onSchedule,
    quoting,
    onCancelQuote,
    onTyping,
}: ComposerProps) {
    const { t } = useTranslate();
    const formats = useFormats();
    const { entries: custom } = useCustomEmoji();

    /*
     * The draft is the field.
     *
     * Read from an external store rather than held in component state: the
     * value lives in the browser's storage, which the server cannot see, so
     * reading it while rendering would put a filled field in one tree and an
     * empty one in the other — which React refuses to hydrate.
     * useSyncExternalStore is the shape React provides for exactly that, and it
     * is what use-appearance in this project already uses.
     *
     * A composer with no draftKey gets a store too, keyed to this instance and
     * kept in memory only: one shape for both, rather than two code paths that
     * behave subtly differently.
     */
    const instanceKey = useId();
    const key = draftKey ?? `ephemeral:${instanceKey}`;

    const body = useSyncExternalStore(
        useCallback(
            (callback: () => void) => subscribeToDraft(key, callback),
            [key],
        ),
        useCallback(() => readDraft(key), [key]),
        readDraftOnServer,
    );

    /** Type into the field. The store is what the field shows. */
    const write = (value: string) => saveDraft(key, value);

    const [active, setActive] = useState<ActiveTrigger | null>(null);
    const [highlighted, setHighlighted] = useState(0);
    /**
     * The moment picked for later, or null while sending now.
     *
     * Kept beside the body rather than in a dialog of its own: what you are
     * about to say and when it goes out are one decision, and a modal over the
     * field you are typing in hides the thing being scheduled.
     */
    const [sendAt, setSendAt] = useState<string | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    /**
     * The files picked but not yet sent.
     *
     * Held here rather than left in the file input, because they arrive from
     * three places — the picker, a drop, and a paste — and only one of those
     * has an input behind it.
     */
    const [files, setFiles] = useState<File[]>([]);
    const [tooLarge, setTooLarge] = useState<string[]>([]);
    const [dragging, setDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const recorder = useVoiceRecorder();

    /*
     * Only where the workspace takes audio at all. The endpoint checks the
     * same thing, so this spares somebody a recording that would be refused
     * after they made it.
     */
    const canRecord =
        attachments !== undefined &&
        recorder.state !== 'unavailable' &&
        attachments.accept.includes('audio/');

    /**
     * Take on what fits, and name what does not.
     *
     * The size is checked here as well as on the server. Not because the
     * browser is trusted — it is not — but because a member who drops in a
     * 400 MB video deserves to hear about it now rather than after the upload.
     */
    const addFiles = (incoming: File[]) => {
        if (!attachments || incoming.length === 0) {
            return;
        }

        const maxBytes = attachments.maxKb * 1024;
        const rejected = incoming.filter((file) => file.size > maxBytes);

        setTooLarge(rejected.map((file) => file.name));
        setFiles((current) =>
            [...current, ...incoming.filter((file) => file.size <= maxBytes)]
                // Ten is what the endpoint takes; more would be refused as a
                // whole, losing the nine that were fine.
                .slice(0, MAX_ATTACHMENTS),
        );
    };

    const suggestions: Suggestion[] = (() => {
        if (active === null) {
            return [];
        }

        if (active.char === '/') {
            return (commands ?? [])
                .filter((command) => command.name.startsWith(active.query))
                .slice(0, MAX_SUGGESTIONS)
                .map((command) => ({
                    key: `command-${command.name}`,
                    insert: command.name,
                    primary: `/${command.name}`,
                    secondary: command.description,
                    icon: 'command' as const,
                    run: command.run,
                }));
        }

        if (active.char === ':') {
            if (active.query.length < EMOJI_QUERY_FLOOR) {
                return [];
            }

            /*
             * This workspace's own first, matched on the name people type.
             * They are the ones somebody is reaching for when they open the
             * colon at all — the unicode set below is on every keyboard, and
             * ":shipit" is not.
             */
            const own: Suggestion[] = custom
                .filter((entry) => entry.name.startsWith(active.query))
                .map((entry) => ({
                    key: `custom-${entry.name}`,
                    // The colons stay in the message: they are what turns the
                    // name back into a picture wherever it is read.
                    insert: `:${entry.name}:`,
                    primary: `:${entry.name}:`,
                    secondary: t('composer.suggestions.custom_emoji'),
                    icon: 'emoji' as const,
                    replacesTrigger: true,
                }));

            return own
                .concat(
                    EMOJI_GROUPS.flatMap((group) => group.entries)
                        .filter((entry) =>
                            entry.keywords.some((keyword) =>
                                keyword.startsWith(active.query),
                            ),
                        )
                        .slice(0, MAX_SUGGESTIONS)
                        .map((entry) => ({
                            key: `emoji-${entry.emoji}`,
                            insert: entry.emoji,
                            primary: entry.emoji,
                            secondary: entry.keywords.join(', '),
                            icon: 'emoji' as const,
                            replacesTrigger: true,
                        })),
                )
                .slice(0, MAX_SUGGESTIONS);
        }

        if (active.char === '@') {
            // Group handles sit above the people: they are what someone is
            // reaching for when they type "@e", and burying them under a
            // near-match on a colleague's name makes them hard to find.
            const broadcasts: Suggestion[] = workspace.canBroadcastMention
                ? [
                      {
                          key: 'broadcast-here',
                          insert: 'here',
                          primary: '@here',
                          secondary: t('composer.suggestions.here'),
                          icon: 'broadcast' as const,
                      },
                      {
                          key: 'broadcast-everyone',
                          insert: 'everyone',
                          primary: '@everyone',
                          secondary: t('composer.suggestions.everyone', {
                              count: memberCount,
                          }),
                          icon: 'broadcast' as const,
                      },
                  ].filter((option) => option.insert.startsWith(active.query))
                : [];

            return broadcasts
                .concat(
                    members
                        .filter(
                            (member) =>
                                member.username
                                    .toLowerCase()
                                    .includes(active.query) ||
                                member.name
                                    .toLowerCase()
                                    .includes(active.query),
                        )
                        .map((member) => ({
                            key: `member-${member.id}`,
                            insert: member.username,
                            primary: member.name,
                            secondary: `@${member.username}`,
                            icon: 'member' as const,
                        })),
                )
                .slice(0, MAX_SUGGESTIONS);
        }

        return channels
            .filter((channel) => (channel.name ?? '').includes(active.query))
            .slice(0, MAX_SUGGESTIONS)
            .map((channel) => ({
                key: `channel-${channel.id}`,
                insert: channel.name ?? '',
                primary: `#${channel.name}`,
                secondary: channel.isMember ? undefined : 'geen lid',
                icon:
                    channel.type === 'private'
                        ? ('private' as const)
                        : ('public' as const),
            }));
    })();

    /*
     * The slash joins the list only where there is something to offer, so a
     * field without commands treats "/" as an ordinary character — which is
     * what it is in "en/of".
     */
    const activeTriggers =
        commands && commands.length > 0 ? `${triggers}/` : triggers;

    const resize = () => {
        const textarea = textareaRef.current;

        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = `${Math.min(textarea.scrollHeight, MAX_ROWS_HEIGHT)}px`;
        }
    };

    const complete = (suggestion: Suggestion) => {
        /*
         * A command empties the field and does its thing. Nothing is written,
         * because "/versturen" is not something anybody wants left in the
         * message they are about to send.
         */
        if (suggestion.run) {
            write('');
            setActive(null);
            suggestion.run();

            return;
        }

        const textarea = textareaRef.current;
        const caret = textarea?.selectionStart ?? body.length;
        const trigger = active?.char ?? '@';
        const before = body
            .slice(0, caret)
            .replace(new RegExp(`[${TRIGGERS}]${FRAGMENT}$`, 'i'), '');
        // An emoji swallows its own trigger — ":taart" becomes "🎂", where
        // "@fenna" stays "@fenna".
        const written = suggestion.replacesTrigger
            ? suggestion.insert
            : `${trigger}${suggestion.insert}`;
        const next = `${before}${written} ${body.slice(caret)}`;

        write(next);
        setActive(null);

        requestAnimationFrame(() => {
            resize();
            const position = before.length + written.length + 1;
            textarea?.focus();
            textarea?.setSelectionRange(position, position);
        });
    };

    // Picking "Citeren" up in the message list should put the caret here, or
    // the member has to click the field before they can start typing.
    useEffect(() => {
        if (quoting) {
            textareaRef.current?.focus();
        }
    }, [quoting]);

    /**
     * Write an emoji where the caret is.
     *
     * At the caret rather than at the end: somebody who stops halfway through a
     * sentence to add one means it to go there, and appending would make the
     * picker useless for anything but the last word.
     */
    const insertEmoji = (emoji: string) => {
        const textarea = textareaRef.current;
        const caret = textarea?.selectionStart ?? body.length;
        const next = `${body.slice(0, caret)}${emoji}${body.slice(caret)}`;

        write(next);

        requestAnimationFrame(() => {
            resize();
            textarea?.focus();
            textarea?.setSelectionRange(
                caret + emoji.length,
                caret + emoji.length,
            );
        });
    };

    // A file on its own is a message, so an empty field is no longer a reason
    // not to send. Scheduling is the exception: only the words go out later.
    const canSend = body.trim() !== '' || files.length > 0;

    const submit = () => {
        const trimmed = body.trim();

        if (!canSend || disabled) {
            return;
        }

        if (sendAt !== null && onSchedule) {
            onSchedule(trimmed, sendAt);
            setSendAt(null);
        } else {
            onSend(trimmed, files);
        }

        write('');
        setFiles([]);
        setTooLarge([]);
        setActive(null);
        requestAnimationFrame(resize);
    };

    return (
        <div className="relative px-4 pt-1 pb-4">
            {suggestions.length > 0 && (
                <ul
                    role="listbox"
                    aria-label={
                        active?.char === '/'
                            ? t('composer.suggestions.command')
                            : active?.char === '#'
                              ? t('composer.suggestions.channel')
                              : t('composer.suggestions.member')
                    }
                    /*
                        Wide enough for a command and what it does, and capped
                        against the field rather than at a fixed width: 18rem
                        cut "/versturen" off halfway through its description,
                        and anything wider than the composer would hang off the
                        side of a phone.
                    */
                    className="absolute bottom-full left-4 z-10 mb-1 w-[min(26rem,calc(100%-2rem))] overflow-hidden rounded-lg border bg-popover shadow-md"
                >
                    {suggestions.map((suggestion, index) => (
                        <li key={suggestion.key}>
                            <button
                                type="button"
                                role="option"
                                aria-selected={index === highlighted}
                                // The textarea must not lose focus, or the caret
                                // position we insert at is gone by the time the
                                // click handler runs.
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => complete(suggestion)}
                                onMouseEnter={() => setHighlighted(index)}
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                    index === highlighted && 'bg-accent',
                                )}
                            >
                                <SuggestionIcon
                                    icon={suggestion.icon}
                                    name={suggestion.primary}
                                />
                                {/*
                                    Stacked rather than side by side, because
                                    the two used to share one line and truncate
                                    against each other — the name is what is
                                    being typed, so it is the one thing that
                                    must never be cut off.
                                */}
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate font-medium">
                                        {suggestion.primary}
                                    </span>
                                    {suggestion.secondary && (
                                        <span className="truncate text-xs text-muted-foreground">
                                            {suggestion.secondary}
                                        </span>
                                    )}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {quoting && (
                <div className="flex items-start gap-2 rounded-t-lg border border-b-0 bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                    <span className="border-l-2 border-primary/40 pl-2 font-medium text-foreground/70">
                        {quoting.author.name}
                    </span>
                    <span className="line-clamp-1 min-w-0 flex-1">
                        {quoting.body}
                    </span>
                    <button
                        type="button"
                        onClick={onCancelQuote}
                        title={t('composer.quote.cancel')}
                        aria-label={t('composer.quote.cancel')}
                        className="shrink-0 rounded p-0.5 hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {/*
                Only once a moment is picked. A date field standing open above
                every message would suggest that scheduling is the normal way to
                say something here.
            */}
            {sendAt !== null && (
                <div className="flex items-center gap-2 rounded-t-lg border border-b-0 bg-muted/50 px-3 py-2 text-xs">
                    <CalendarClock className="size-3.5 shrink-0 text-muted-foreground" />
                    <label
                        htmlFor="composer-send-at"
                        className="text-muted-foreground"
                    >
                        {t('composer.schedule.at')}
                    </label>
                    <input
                        id="composer-send-at"
                        type="datetime-local"
                        value={sendAt}
                        onChange={(event) => setSendAt(event.target.value)}
                        className="rounded border bg-background px-1.5 py-0.5 focus-visible:ring-2 focus-visible:outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => setSendAt(null)}
                        title={t('composer.schedule.send_now')}
                        aria-label={t('composer.schedule.send_now')}
                        className="ml-auto shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            )}

            {/*
                What is about to go along with the message. Above the field
                rather than below the send button: it is part of what you are
                writing, and you should see it while you write.
            */}
            {files.length > 0 && (
                <ul className="flex flex-wrap gap-2 rounded-t-lg border border-b-0 bg-muted/50 px-3 py-2">
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${index}`}
                            className="flex max-w-full items-center gap-2 rounded border bg-background px-2 py-1 text-xs"
                        >
                            <Paperclip className="size-3 shrink-0 text-muted-foreground" />
                            <span className="truncate">{file.name}</span>
                            <span className="shrink-0 text-muted-foreground">
                                {readableSize(file.size, formats.number)}
                            </span>
                            <button
                                type="button"
                                onClick={() =>
                                    setFiles((current) =>
                                        current.filter((_, at) => at !== index),
                                    )
                                }
                                aria-label={`${file.name} niet meesturen`}
                                className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-destructive"
                            >
                                <X className="size-3" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {recorder.state === 'recording' && (
                <div className="flex items-center gap-2 rounded-t-lg border border-b-0 bg-muted/50 px-3 py-2 text-xs">
                    <span
                        aria-hidden
                        className="size-2 animate-pulse rounded-full bg-red-500"
                    />
                    <span className="font-medium">
                        {t('composer.recording.in_progress')}
                    </span>
                    <span className="font-mono text-muted-foreground">
                        {Math.floor(recorder.seconds / 60)}:
                        {String(recorder.seconds % 60).padStart(2, '0')}
                    </span>
                    <button
                        type="button"
                        onClick={recorder.cancel}
                        className="ml-auto rounded p-0.5 text-muted-foreground hover:text-destructive"
                        aria-label={t('composer.recording.discard')}
                        title={t('composer.recording.discard')}
                    >
                        <X className="size-3" />
                    </button>
                </div>
            )}

            {tooLarge.length > 0 && (
                <p className="px-1 pb-1 text-xs text-destructive">
                    {t('composer.attachment.too_large', {
                        files: tooLarge.join(', '),
                        max: readableSize(
                            (attachments?.maxKb ?? 0) * 1024,
                            formats.number,
                        ),
                    })}
                    {/*
                        Points at the way out rather than leaving somebody
                        stuck: this is the exact moment the other feature is
                        the answer, and the only moment it is obvious. Read off
                        the command list rather than off a callback, so the
                        sentence cannot promise something the field does not
                        actually offer.
                    */}
                    {(commands ?? []).some(
                        (command) => command.name === 'versturen',
                    ) && <> {t('composer.attachment.use_transfer')}</>}
                </p>
            )}

            <div
                onDragOver={
                    attachments
                        ? (event) => {
                              event.preventDefault();
                              setDragging(true);
                          }
                        : undefined
                }
                onDragLeave={attachments ? () => setDragging(false) : undefined}
                onDrop={
                    attachments
                        ? (event) => {
                              event.preventDefault();
                              setDragging(false);
                              addFiles(Array.from(event.dataTransfer.files));
                          }
                        : undefined
                }
                className={cn(
                    'flex items-end gap-2 border bg-background p-2 transition-shadow focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30',
                    // The bar above and the field below read as one control.
                    quoting ||
                        sendAt !== null ||
                        files.length > 0 ||
                        recorder.state === 'recording'
                        ? 'rounded-b-lg'
                        : 'rounded-lg',
                    dragging && 'border-primary ring-2 ring-primary/30',
                )}
            >
                <textarea
                    ref={textareaRef}
                    value={body}
                    disabled={disabled}
                    rows={1}
                    placeholder={placeholder}
                    onChange={(event) => {
                        write(event.target.value);
                        setActive(
                            triggerAt(
                                event.target.value,
                                event.target.selectionStart,
                                activeTriggers,
                            ),
                        );
                        setHighlighted(0);
                        resize();

                        if (event.target.value !== '') {
                            onTyping?.();
                        }
                    }}
                    onBlur={() => setActive(null)}
                    onPaste={
                        attachments
                            ? (event) => {
                                  // Only when the clipboard actually holds a
                                  // file: pasting text must stay pasting text,
                                  // and a copied screenshot arrives as both.
                                  const pasted = Array.from(
                                      event.clipboardData.files,
                                  );

                                  if (pasted.length > 0) {
                                      event.preventDefault();
                                      addFiles(pasted);
                                  }
                              }
                            : undefined
                    }
                    onKeyDown={(event) => {
                        if (suggestions.length > 0) {
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                setHighlighted(
                                    (current) =>
                                        (current + 1) % suggestions.length,
                                );

                                return;
                            }

                            if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                setHighlighted(
                                    (current) =>
                                        (current - 1 + suggestions.length) %
                                        suggestions.length,
                                );

                                return;
                            }

                            if (event.key === 'Enter' || event.key === 'Tab') {
                                event.preventDefault();
                                complete(suggestions[highlighted]);

                                return;
                            }

                            if (event.key === 'Escape') {
                                event.preventDefault();
                                setActive(null);

                                return;
                            }
                        }

                        /*
                         * Escape steps back out of one layer at a time. The
                         * suggestion list is handled above and returns there,
                         * so this only ever runs once the list is closed —
                         * pressing it with both open would otherwise throw the
                         * quote away while somebody was only dismissing a
                         * dropdown they did not mean to open.
                         */
                        if (
                            event.key === 'Escape' &&
                            quoting &&
                            onCancelQuote
                        ) {
                            event.preventDefault();
                            onCancelQuote();

                            return;
                        }

                        // Enter sends, Shift+Enter starts a new line.
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            submit();
                        }
                    }}
                    className="max-h-[200px] flex-1 resize-none bg-transparent px-2 py-1.5 text-sm leading-relaxed focus:outline-none disabled:opacity-60"
                />
                {/*
                    The same picker the message rows use, so what you last
                    reacted with is also what it offers here — one list of
                    recents rather than two that disagree.
                */}
                <ReactionPicker
                    label="Emoji invoegen"
                    triggerClassName="flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                    onSelect={insertEmoji}
                />

                {canRecord && (
                    <Button
                        size="icon"
                        variant={
                            recorder.state === 'recording' ? 'default' : 'ghost'
                        }
                        disabled={disabled}
                        onClick={async () => {
                            if (recorder.state === 'recording') {
                                const note = await recorder.stop();

                                if (note) {
                                    addFiles([note]);
                                }

                                return;
                            }

                            await recorder.start();
                        }}
                        title={
                            recorder.state === 'recording'
                                ? t('composer.recording.stop')
                                : t('composer.recording.start')
                        }
                        aria-label={
                            recorder.state === 'recording'
                                ? t('composer.recording.stop')
                                : t('composer.recording.start')
                        }
                    >
                        {recorder.state === 'recording' ? (
                            <Square className="size-4" />
                        ) : (
                            <Mic className="size-4" />
                        )}
                    </Button>
                )}

                {attachments && (
                    <>
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept={attachments.accept}
                            className="hidden"
                            onChange={(event) => {
                                addFiles(Array.from(event.target.files ?? []));

                                // Cleared, or picking the same file twice in a
                                // row fires no change event the second time.
                                event.target.value = '';
                            }}
                        />
                        <Button
                            size="icon"
                            variant="ghost"
                            disabled={disabled || sendAt !== null}
                            onClick={() => fileInputRef.current?.click()}
                            title={
                                sendAt === null
                                    ? t('composer.attachment.add')
                                    : t(
                                          'composer.attachment.not_when_scheduled',
                                      )
                            }
                            aria-label={t('composer.attachment.add')}
                        >
                            <Paperclip className="size-4" />
                        </Button>
                    </>
                )}
                {/*
                    Not offered while files are waiting: only the words would go
                    out later, and a paperclip that quietly drops its file is
                    worse than no paperclip.
                */}
                {onSchedule && sendAt === null && files.length === 0 && (
                    <Button
                        size="icon"
                        variant="ghost"
                        disabled={disabled}
                        // Ten minutes out rather than now: the field opens on a
                        // moment that is already valid, so picking a time is a
                        // correction instead of a requirement.
                        onClick={() => setSendAt(defaultSendAt())}
                        title={t('composer.schedule.later')}
                        aria-label={t('composer.schedule.later')}
                    >
                        <CalendarClock className="size-4" />
                    </Button>
                )}
                <Button
                    size="icon"
                    onClick={submit}
                    disabled={disabled || !canSend}
                    aria-label={
                        sendAt === null
                            ? t('composer.schedule.send')
                            : t('composer.schedule.plan')
                    }
                >
                    {sendAt === null ? (
                        <SendHorizonal className="size-4" />
                    ) : (
                        <CalendarClock className="size-4" />
                    )}
                </Button>
            </div>
            <p className="mt-1.5 px-1 text-xs text-muted-foreground">
                <kbd className="rounded bg-muted px-1 font-mono">Enter</kbd>{' '}
                {t('composer.hints.send')} ·{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    Shift+Enter
                </kbd>{' '}
                {t('composer.hints.newline')} ·{' '}
                {triggers.includes('@') && (
                    <>
                        <kbd className="rounded bg-muted px-1 font-mono">@</kbd>{' '}
                        {t('composer.hints.member')} ·{' '}
                    </>
                )}
                {triggers.includes('#') && (
                    <>
                        <kbd className="rounded bg-muted px-1 font-mono">#</kbd>{' '}
                        {t('composer.hints.channel')} ·{' '}
                    </>
                )}
                <kbd className="rounded bg-muted px-1 font-mono">
                    {t('composer.hints.bold')}
                </kbd>{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    {t('composer.hints.italic')}
                </kbd>{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    {t('composer.hints.strike')}
                </kbd>{' '}
                {/*
                    Last, and the only one showing two forms. A code block is
                    the one piece of syntax here nobody guesses from seeing the
                    result — the others announce themselves once you have read a
                    message that used them, and ``` does not.
                */}
                <kbd className="rounded bg-muted px-1 font-mono">
                    {t('composer.hints.code')}
                </kbd>{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    {t('composer.hints.code_block')}
                </kbd>
            </p>
        </div>
    );
}
