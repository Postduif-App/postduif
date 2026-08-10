import { router } from '@inertiajs/react';
import {
    AtSign,
    BarChart3,
    FileText,
    Hash,
    KeyRound,
    Lock,
    Megaphone,
    MessageSquare,
    Plus,
    Send,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useTranslate } from '@/hooks/use-translate';
import { matchChannels } from '@/lib/channel-search';
import {
    parseSearchQuery,
    trailingFilter,
    withFilter,
} from '@/lib/search-filters';
import { search, show } from '@/routes/chat';
import type {
    ChannelMember,
    ChannelSummary,
    ChatWorkspace,
    DocumentHit,
    SearchHit,
} from '@/types/chat';

interface SearchDialogProps {
    workspace: ChatWorkspace;
    /**
     * The same lists the sidebar draws. Handed over rather than fetched: they
     * are already on the page, and jumping to a channel has to happen between
     * keystrokes.
     */
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    /**
     * Everybody in the workspace, for completing "from:".
     *
     * Optional, and empty where the member panel is switched off — completion
     * follows the same rule as the directory, because a dropdown listing every
     * colleague is a directory. Typing a handle by hand still works: the filter
     * itself is not gated, only the offer to fill it in.
     */
    workspaceMembers?: ChannelMember[];
    open: boolean;
    /**
     * What the field starts with when it opens, or nothing.
     *
     * Used by the channel header's search button, which opens this with
     * "in:algemeen " already typed so the reader can go straight on.
     */
    prefill?: string;
    onOpenChange: (open: boolean) => void;
    /**
     * The things the palette can do besides going somewhere.
     *
     * Every one of these already exists as a button in the sidebar with a
     * callback behind it; the palette borrows the same callbacks rather than
     * opening the dialogs itself. Two paths to one dialog is how the two drift
     * apart.
     *
     * Optional throughout, because what a member may do differs — the caller
     * leaves out what this person cannot reach here.
     *
     */
    actions?: {
        onCreateChannel?: () => void;
        onStartDirectMessage?: () => void;
        onInvitePeople?: () => void;
        onBroadcast?: () => void;
        /**
         * Only ever handed over by the channel screen: both act on one channel,
         * and this palette also opens where there is none.
         */
        onSendFiles?: () => void;
        onAskSecret?: () => void;
        /** The mirror of onAskSecret: handing one over instead of asking. */
        onSendSecret?: () => void;
        onAskPoll?: () => void;
    };
}

const DEBOUNCE_MS = 200;

/**
 * One recognised filter, shown above the field.
 *
 * There so that "in:algemeen" visibly stops being three words and becomes a
 * setting: without it there is nothing to tell somebody whether their filter
 * was understood or is simply being searched for as text.
 *
 * Clicking it takes the filter back out, which is quicker than finding the
 * right spot in the field and deleting exactly the right characters.
 */
function FilterChip({
    icon: Icon,
    label,
    onRemove,
}: {
    icon: typeof Hash;
    label: string;
    onRemove: () => void;
}) {
    const { t } = useTranslate();

    return (
        <button
            type="button"
            onClick={onRemove}
            className="flex items-center gap-1 rounded-full border border-border/70 px-2 py-px text-xs text-muted-foreground transition-colors hover:border-border hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
            aria-label={t('search.palette.remove_filter', { label })}
        >
            <Icon className="size-3 shrink-0" />
            <span className="max-w-40 truncate">{label}</span>
            <X className="size-3 shrink-0 opacity-50" />
        </button>
    );
}

export function SearchDialog({
    prefill,
    workspaceMembers = [],
    workspace,
    channels,
    directMessages,
    open,
    onOpenChange,
    actions = {},
}: SearchDialogProps) {
    const { t, tChoice } = useTranslate();
    const [query, setQuery] = useState('');

    /*
     * Seeded on the rising edge of `open`, not in an effect: an effect would
     * paint the empty field first and then fill it, which is a visible flicker
     * on a dialog that appears under the caret. Adjusting state during render
     * is React's own answer for state that follows a prop.
     *
     * Tracked rather than keyed off `open` alone so that closing and reopening
     * seeds again, while typing inside an open dialog does not.
     */
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setQuery(prefill ?? '');
        }
    }

    const inputRef = useRef<HTMLInputElement>(null);

    /**
     * Write into the field and leave the caret after it, ready to type on.
     *
     * Every one of these calls is the machine writing, not the person: picking
     * a channel from the list, taking a filter back off. Left alone the browser
     * hands the field back with its whole contents selected, so the next
     * keystroke wipes the filter that was just switched on — and the reader's
     * own next word is what does it.
     *
     * On the next frame rather than straight away: cmdk moves focus back to the
     * input after an item is chosen, and a caret set before that happens is a
     * caret that gets moved again.
     */
    const applyQuery = (next: string) => {
        setQuery(next);

        requestAnimationFrame(() => {
            const input = inputRef.current;

            if (input === null) {
                return;
            }

            input.focus();
            input.setSelectionRange(next.length, next.length);
        });
    };

    /*
     * The same courtesy for a dialog that opened with a filter already in it —
     * "in:algemeen " is a starting point, not a thing to overtype.
     */
    useEffect(() => {
        if (!open) {
            return;
        }

        requestAnimationFrame(() => {
            const input = inputRef.current;

            input?.setSelectionRange(input.value.length, input.value.length);
        });
    }, [open]);

    const [hits, setHits] = useState<SearchHit[]>([]);
    const [documentHits, setDocumentHits] = useState<DocumentHit[]>([]);
    const [loading, setLoading] = useState(false);

    const trimmed = query.trim();

    /*
     * The filters come out here rather than on the server. What gets sent is a
     * channel name in its own parameter and the words in another — user text
     * never becomes something the backend has to take apart to decide what may
     * be read.
     */
    const filters = parseSearchQuery(trimmed);

    /*
     * Where somebody could jump to. Worked out on every keystroke and thrown
     * away — it is a filter over a list that is already here, so there is
     * nothing to memoise and nothing to wait for.
     */
    const everywhere = [...channels, ...directMessages];

    /*
     * What an empty palette offers.
     *
     * Without this the dialog is a blank box until somebody types, which makes
     * it useless to the one person who most needs it: whoever does not yet know
     * what is in there. Unread first, then favourites, then whatever is left —
     * the order somebody would have gone looking in anyway.
     */
    const suggested = [...everywhere]
        .sort((a, b) => {
            const unread = b.unreadCount - a.unreadCount;

            if (unread !== 0) {
                return unread;
            }

            if (a.isFavorite !== b.isFavorite) {
                return a.isFavorite ? -1 : 1;
            }

            return a.label.localeCompare(b.label);
        })
        .slice(0, 5);

    /*
     * A filter being typed right now. While there is one, the palette stops
     * offering places to go and starts offering values to complete it —
     * "in:alge" is not somebody trying to reach a channel, it is somebody
     * telling the search where to look.
     */
    const completing = trailingFilter(query);

    const completions =
        completing?.name === 'in'
            ? matchChannels(
                  everywhere.filter((row) => row.type !== 'dm'),
                  completing.value,
              ).slice(0, 8)
            : [];

    /*
     * Matched on the handle, which is what "from:" takes, and on the name,
     * which is what somebody remembers. Typing "fenna" should find @fdv.
     */
    const people =
        completing?.name === 'from'
            ? workspaceMembers
                  .filter(
                      (member) =>
                          member.username
                              .toLowerCase()
                              .includes(completing.value) ||
                          member.name.toLowerCase().includes(completing.value),
                  )
                  .slice(0, 8)
            : [];

    const jumps = completing
        ? []
        : trimmed === ''
          ? suggested
          : matchChannels(everywhere, trimmed);

    /*
     * What this member can do from here. Built as a list so the filtering below
     * is one line rather than a condition per row, and so the order is visible
     * in one place.
     */
    const commands = [
        {
            key: 'create-channel',
            label: t('search.commands.new_channel'),
            icon: Plus,
            run: actions.onCreateChannel,
        },
        {
            key: 'direct-message',
            label: t('search.commands.direct_message'),
            icon: MessageSquare,
            run: actions.onStartDirectMessage,
        },
        {
            key: 'send-files',
            label: t('search.commands.send_files'),
            icon: Send,
            run: actions.onSendFiles,
        },
        {
            key: 'ask-secret',
            label: t('search.commands.ask_secret'),
            icon: KeyRound,
            run: actions.onAskSecret,
        },
        {
            key: 'send-secret',
            // Next to asking rather than next to sending files, because the two
            // secret actions are each other's mirror and somebody looking for
            // one will read past the other on the way.
            label: t('search.commands.send_secret'),
            icon: KeyRound,
            run: actions.onSendSecret,
        },
        {
            key: 'ask-poll',
            label: t('search.commands.ask_poll'),
            icon: BarChart3,
            run: actions.onAskPoll,
        },
        {
            key: 'broadcast',
            label: t('search.commands.broadcast'),
            icon: Megaphone,
            run: actions.onBroadcast,
        },
        {
            key: 'invite',
            label: t('search.commands.invite'),
            icon: UserPlus,
            run: actions.onInvitePeople,
        },
    ].filter(
        (command): command is typeof command & { run: () => void } =>
            command.run !== undefined &&
            // Matched on the label, the same words somebody sees. No fuzzy
            // pass here: an action that fires by accident is worse than one
            // that takes another letter to find.
            (trimmed === '' ||
                command.label.toLowerCase().includes(trimmed.toLowerCase())),
    );
    // Derived rather than stored: an empty query has no results by definition,
    // so there is nothing to synchronise back into state.
    const results = trimmed === '' ? [] : hits;
    const documentResults = trimmed === '' ? [] : documentHits;

    useEffect(() => {
        // Nothing typed, nothing asked: the empty palette is built entirely
        // from what the page already has, so it costs no request at all.
        // A query that is nothing but "in:algemeen" has no words to look for
        // yet; searching on it would return the channel's most recent fifty
        // messages, which is not what somebody halfway through typing meant.
        if (!open || filters.terms === '') {
            return;
        }

        // Abort the in-flight request on every keystroke so a slow early query
        // cannot overwrite the results of a later one.
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    search.url(workspace.slug, {
                        query: {
                            q: filters.terms,
                            ...(filters.channel ? { in: filters.channel } : {}),
                            ...(filters.from ? { from: filters.from } : {}),
                        },
                    }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();
                setHits(payload.results ?? []);
                setDocumentHits(payload.documents ?? []);
            } catch (error) {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setHits([]);
                    setDocumentHits([]);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
        // The two values rather than the object they came in: parseSearchQuery
        // hands back a fresh object every render, and depending on that would
        // re-fire the search on every keystroke that changed nothing.
    }, [open, filters.terms, filters.channel, filters.from, workspace.slug]);

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('search.palette.title')}
            description={t('search.palette.description')}
            shouldFilter={false}
        >
            <CommandInput
                ref={inputRef}
                placeholder={t('search.palette.placeholder')}
                value={query}
                onValueChange={setQuery}
            />

            {/*
                Under the field rather than inside it: a chip that sat between
                the caret and the text would move while somebody types, and
                this row appears and disappears as filters come and go.
            */}
            {(filters.channel || filters.from) && (
                <div className="flex flex-wrap items-center gap-1.5 border-b px-3 py-1.5">
                    {filters.channel && (
                        <FilterChip
                            icon={Hash}
                            label={filters.channel}
                            onRemove={() =>
                                applyQuery(withFilter(query, 'in', null))
                            }
                        />
                    )}
                    {filters.from && (
                        <FilterChip
                            icon={AtSign}
                            label={filters.from}
                            onRemove={() =>
                                applyQuery(withFilter(query, 'from', null))
                            }
                        />
                    )}
                </div>
            )}
            <CommandList>
                {!loading &&
                    trimmed !== '' &&
                    results.length === 0 &&
                    jumps.length === 0 &&
                    commands.length === 0 && (
                        <CommandEmpty>{t('search.palette.empty')}</CommandEmpty>
                    )}

                {/*
                    Above the messages on purpose: going somewhere is what a
                    palette is mostly for, and it is the half that answers
                    instantly. Search results arrive a moment later and must not
                    push the jumps around when they do.
                */}
                {completions.length > 0 && (
                    <CommandGroup heading={t('search.headings.searching_in')}>
                        {completions.map((channel) => (
                            <CommandItem
                                key={`in-${channel.id}`}
                                value={`in-${channel.id}`}
                                onSelect={() =>
                                    applyQuery(
                                        withFilter(query, 'in', channel.label),
                                    )
                                }
                            >
                                <Hash className="mr-2 size-4 text-muted-foreground" />
                                <span className="truncate">
                                    {channel.label}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}

                {people.length > 0 && (
                    <CommandGroup heading={t('search.headings.from')}>
                        {people.map((member) => (
                            <CommandItem
                                key={`from-${member.id}`}
                                value={`from-${member.id}`}
                                onSelect={() =>
                                    applyQuery(
                                        withFilter(
                                            query,
                                            'from',
                                            member.username,
                                        ),
                                    )
                                }
                            >
                                <AtSign className="mr-2 size-4 text-muted-foreground" />
                                <span className="truncate">{member.name}</span>
                                <span className="ml-1 truncate text-xs text-muted-foreground">
                                    @{member.username}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}

                {jumps.length > 0 && (
                    <CommandGroup
                        heading={
                            trimmed === ''
                                ? t('search.headings.quick')
                                : t('search.headings.jump')
                        }
                    >
                        {jumps.map((channel) => (
                            <CommandItem
                                key={`jump-${channel.id}`}
                                value={`jump-${channel.id}`}
                                onSelect={() => {
                                    onOpenChange(false);
                                    router.visit(
                                        show({
                                            workspace: workspace.slug,
                                            channel: channel.id,
                                        }),
                                    );
                                }}
                            >
                                {channel.type === 'dm' ? (
                                    <MessageSquare className="size-3.5 text-muted-foreground" />
                                ) : channel.type === 'private' ? (
                                    <Lock className="size-3.5 text-muted-foreground" />
                                ) : (
                                    <Hash className="size-3.5 text-muted-foreground" />
                                )}
                                <span className="truncate">
                                    {channel.label}
                                </span>
                                {channel.unreadCount > 0 && (
                                    <span className="ml-auto rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold">
                                        {channel.unreadCount}
                                    </span>
                                )}
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}

                {commands.length > 0 && (
                    <CommandGroup heading={t('search.headings.actions')}>
                        {commands.map((command) => (
                            <CommandItem
                                key={command.key}
                                value={`action-${command.key}`}
                                onSelect={() => {
                                    /*
                                     * Close first, then open. The dialogs this
                                     * hands off to are driven from outside, and
                                     * mounting one while this is still closing
                                     * leaves two overlays fighting over the
                                     * page's focus.
                                     */
                                    onOpenChange(false);
                                    command.run();
                                }}
                            >
                                <command.icon className="size-3.5 text-muted-foreground" />
                                {command.label}
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}

                {documentResults.length > 0 && (
                    /*
                        Above the messages rather than below them. A document
                        that matches is nearly always a better answer than a
                        remark about the same subject — the remark is one
                        moment, the document is what the channel settled on.
                    */
                    <CommandGroup
                        heading={tChoice(
                            'search.palette.documents',
                            documentResults.length,
                        )}
                    >
                        {documentResults.map((hit) => (
                            <CommandItem
                                key={`document-${hit.id}`}
                                value={`document-${hit.id}`}
                                onSelect={() => {
                                    onOpenChange(false);
                                    router.visit(
                                        show(
                                            {
                                                workspace: workspace.slug,
                                                channel: hit.channel.id,
                                            },
                                            {
                                                query: {
                                                    view: 'documents',
                                                    document: hit.number,
                                                },
                                            },
                                        ),
                                    );
                                }}
                                className="flex flex-col items-start gap-0.5"
                            >
                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <FileText className="size-3" />
                                    {hit.channel.name} · {hit.title}
                                </span>
                                <span className="line-clamp-2 text-sm">
                                    {hit.snippet}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}

                {results.length > 0 && (
                    <CommandGroup
                        heading={tChoice(
                            'search.palette.results',
                            results.length,
                        )}
                    >
                        {results.map((hit) => (
                            <CommandItem
                                key={hit.id}
                                value={hit.id}
                                onSelect={() => {
                                    onOpenChange(false);
                                    router.visit(
                                        show(
                                            {
                                                workspace: workspace.slug,
                                                channel: hit.channel.id,
                                            },
                                            // A reply lives in a thread, and
                                            // the thread panel opens from the
                                            // URL — so the result decides what
                                            // is open, not just where we land.
                                            hit.threadId
                                                ? {
                                                      query: {
                                                          thread: hit.threadId,
                                                      },
                                                  }
                                                : undefined,
                                        ),
                                    );
                                }}
                                className="flex flex-col items-start gap-0.5"
                            >
                                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    {hit.channel.type === 'dm' ? (
                                        <MessageSquare className="size-3" />
                                    ) : hit.channel.type === 'private' ? (
                                        <Lock className="size-3" />
                                    ) : (
                                        <Hash className="size-3" />
                                    )}
                                    {hit.channel.name ??
                                        t('search.palette.direct_message')}{' '}
                                    · {hit.author}
                                    {hit.authorIsBot && (
                                        <span className="rounded-sm bg-muted px-1 py-px text-[10px] font-semibold tracking-wide uppercase">
                                            {t('search.palette.bot')}
                                        </span>
                                    )}
                                </span>
                                <span className="line-clamp-2 text-sm">
                                    {hit.body}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}
            </CommandList>
        </CommandDialog>
    );
}
