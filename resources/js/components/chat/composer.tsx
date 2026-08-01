import {
    CalendarClock,
    Hash,
    Lock,
    Megaphone,
    Mic,
    Paperclip,
    SendHorizonal,
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

import { ReactionPicker } from '@/components/chat/reaction-picker';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { useVoiceRecorder } from '@/hooks/use-voice-recorder';
import {
    readDraft,
    readDraftOnServer,
    saveDraft,
    subscribeToDraft,
} from '@/lib/composer-draft';
import { EMOJI_GROUPS } from '@/lib/emoji';
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

/** Bytes as somebody reads them: "1,4 MB", not "1468006". */
function readableSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    return kb < 1024
        ? `${Math.round(kb)} KB`
        : `${(kb / 1024).toLocaleString('nl-NL', { maximumFractionDigits: 1 })} MB`;
}

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

/** Characters that may follow a trigger; covers handles, slugs and emoji names. */
const FRAGMENT = '[a-z0-9._-]*';

interface Suggestion {
    key: string;
    /** What gets written into the message, without the trigger character. */
    insert: string;
    primary: string;
    secondary?: string;
    icon: 'member' | 'public' | 'private' | 'broadcast' | 'emoji';
    /**
     * What the emoji suggestions carry: the trigger goes away with them, unlike
     * an @handle or a #channel, which keep theirs in the message.
     */
    replacesTrigger?: boolean;
}

interface ActiveTrigger {
    char: string;
    query: string;
}

/**
 * The trigger and fragment directly left of the caret, or null.
 *
 * Anchored to a word boundary so an email address does not open the picker
 * halfway through typing it, and neither does "issue#12".
 */
function triggerAt(
    value: string,
    caret: number,
    triggers: string,
): ActiveTrigger | null {
    if (triggers === '') {
        return null;
    }

    const match = value
        .slice(0, caret)
        .match(new RegExp(`(?:^|\\s)([${triggers}])(${FRAGMENT})$`, 'i'));

    return match ? { char: match[1], query: match[2].toLowerCase() } : null;
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
                {name}
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
    draftKey,
    onSend,
    onSchedule,
    quoting,
    onCancelQuote,
    onTyping,
}: ComposerProps) {
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

        if (active.char === ':') {
            if (active.query.length < EMOJI_QUERY_FLOOR) {
                return [];
            }

            return EMOJI_GROUPS.flatMap((group) => group.entries)
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
                }));
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
                          secondary: 'wie dit kanaal nu open heeft',
                          icon: 'broadcast' as const,
                      },
                      {
                          key: 'broadcast-everyone',
                          insert: 'everyone',
                          primary: '@everyone',
                          secondary: `alle ${memberCount} leden`,
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

    const resize = () => {
        const textarea = textareaRef.current;

        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = `${Math.min(textarea.scrollHeight, MAX_ROWS_HEIGHT)}px`;
        }
    };

    const complete = (suggestion: Suggestion) => {
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
                        active?.char === '#'
                            ? 'Verwijs naar een kanaal'
                            : 'Vermeld een lid'
                    }
                    className="absolute bottom-full left-4 z-10 mb-1 w-72 overflow-hidden rounded-lg border bg-popover shadow-md"
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
                                <span className="truncate font-medium">
                                    {suggestion.primary}
                                </span>
                                {suggestion.secondary && (
                                    <span className="truncate text-xs text-muted-foreground">
                                        {suggestion.secondary}
                                    </span>
                                )}
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
                        title="Citaat weghalen"
                        aria-label="Citaat weghalen"
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
                        Versturen op
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
                        title="Toch nu versturen"
                        aria-label="Toch nu versturen"
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
                                {readableSize(file.size)}
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
                    <span className="font-medium">Aan het opnemen…</span>
                    <span className="font-mono text-muted-foreground">
                        {Math.floor(recorder.seconds / 60)}:
                        {String(recorder.seconds % 60).padStart(2, '0')}
                    </span>
                    <button
                        type="button"
                        onClick={recorder.cancel}
                        className="ml-auto rounded p-0.5 text-muted-foreground hover:text-destructive"
                        aria-label="Opname weggooien"
                        title="Opname weggooien"
                    >
                        <X className="size-3" />
                    </button>
                </div>
            )}

            {tooLarge.length > 0 && (
                <p className="px-1 pb-1 text-xs text-destructive">
                    Te groot om mee te sturen: {tooLarge.join(', ')}. Het
                    maximum is {readableSize((attachments?.maxKb ?? 0) * 1024)}.
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
                                triggers,
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
                                ? 'Opname stoppen en meesturen'
                                : 'Spraakbericht opnemen'
                        }
                        aria-label={
                            recorder.state === 'recording'
                                ? 'Opname stoppen en meesturen'
                                : 'Spraakbericht opnemen'
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
                                    ? 'Bestand meesturen'
                                    : 'Een ingepland bericht kan geen bestand meesturen'
                            }
                            aria-label="Bestand meesturen"
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
                        title="Later versturen"
                        aria-label="Later versturen"
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
                            ? 'Verstuur bericht'
                            : 'Bericht inplannen'
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
                verstuurt ·{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    Shift+Enter
                </kbd>{' '}
                nieuwe regel ·{' '}
                {triggers.includes('@') && (
                    <>
                        <kbd className="rounded bg-muted px-1 font-mono">@</kbd>{' '}
                        lid ·{' '}
                    </>
                )}
                {triggers.includes('#') && (
                    <>
                        <kbd className="rounded bg-muted px-1 font-mono">#</kbd>{' '}
                        kanaal ·{' '}
                    </>
                )}
                <kbd className="rounded bg-muted px-1 font-mono">**vet**</kbd>{' '}
                <kbd className="rounded bg-muted px-1 font-mono">*cursief*</kbd>{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    ~~doorhalen~~
                </kbd>
            </p>
        </div>
    );
}
