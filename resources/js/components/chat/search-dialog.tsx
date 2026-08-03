import { router } from '@inertiajs/react';
import {
    BarChart3,
    Hash,
    KeyRound,
    Lock,
    Megaphone,
    MessageSquare,
    Plus,
    Send,
    UserPlus,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { matchChannels } from '@/lib/channel-search';
import { search, show } from '@/routes/chat';
import type { ChannelSummary, ChatWorkspace, SearchHit } from '@/types/chat';

interface SearchDialogProps {
    workspace: ChatWorkspace;
    /**
     * The same lists the sidebar draws. Handed over rather than fetched: they
     * are already on the page, and jumping to a channel has to happen between
     * keystrokes.
     */
    channels: ChannelSummary[];
    directMessages: ChannelSummary[];
    open: boolean;
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
        onAskPoll?: () => void;
    };
}

const DEBOUNCE_MS = 200;

export function SearchDialog({
    workspace,
    channels,
    directMessages,
    open,
    onOpenChange,
    actions = {},
}: SearchDialogProps) {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<SearchHit[]>([]);
    const [loading, setLoading] = useState(false);

    const trimmed = query.trim();

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

    const jumps =
        trimmed === '' ? suggested : matchChannels(everywhere, trimmed);

    /*
     * What this member can do from here. Built as a list so the filtering below
     * is one line rather than a condition per row, and so the order is visible
     * in one place.
     */
    const commands = [
        {
            key: 'create-channel',
            label: 'Nieuw kanaal',
            icon: Plus,
            run: actions.onCreateChannel,
        },
        {
            key: 'direct-message',
            label: 'Bericht aan iemand',
            icon: MessageSquare,
            run: actions.onStartDirectMessage,
        },
        {
            key: 'send-files',
            label: 'Bestanden versturen',
            icon: Send,
            run: actions.onSendFiles,
        },
        {
            key: 'ask-secret',
            label: 'Om een wachtwoord vragen',
            icon: KeyRound,
            run: actions.onAskSecret,
        },
        {
            key: 'ask-poll',
            label: 'Een vraag stellen',
            icon: BarChart3,
            run: actions.onAskPoll,
        },
        {
            key: 'broadcast',
            label: 'Rondsturen',
            icon: Megaphone,
            run: actions.onBroadcast,
        },
        {
            key: 'invite',
            label: 'Iemand uitnodigen',
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

    useEffect(() => {
        // Nothing typed, nothing asked: the empty palette is built entirely
        // from what the page already has, so it costs no request at all.
        if (!open || trimmed === '') {
            return;
        }

        // Abort the in-flight request on every keystroke so a slow early query
        // cannot overwrite the results of a later one.
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);

            try {
                const response = await fetch(
                    search.url(workspace.slug, { query: { q: trimmed } }),
                    {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    },
                );
                const payload = await response.json();
                setHits(payload.results ?? []);
            } catch (error) {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setHits([]);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, trimmed, workspace.slug]);

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Zoeken of springen"
            description="Spring naar een gesprek, start iets, of zoek door alle berichten die je mag lezen"
            shouldFilter={false}
        >
            <CommandInput
                placeholder="Ga naar een kanaal, of zoek in berichten…"
                value={query}
                onValueChange={setQuery}
            />
            <CommandList>
                {!loading &&
                    trimmed !== '' &&
                    results.length === 0 &&
                    jumps.length === 0 &&
                    commands.length === 0 && (
                        <CommandEmpty>Niets gevonden.</CommandEmpty>
                    )}

                {/*
                    Above the messages on purpose: going somewhere is what a
                    palette is mostly for, and it is the half that answers
                    instantly. Search results arrive a moment later and must not
                    push the jumps around when they do.
                */}
                {jumps.length > 0 && (
                    <CommandGroup
                        heading={trimmed === '' ? 'Snel naar' : 'Springen naar'}
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
                    <CommandGroup heading="Acties">
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

                {results.length > 0 && (
                    <CommandGroup
                        heading={`${results.length} ${results.length === 1 ? 'bericht' : 'berichten'}`}
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
                                    {hit.channel.name ?? 'Direct bericht'} ·{' '}
                                    {hit.author}
                                    {hit.authorIsBot && (
                                        <span className="rounded-sm bg-muted px-1 py-px text-[10px] font-semibold tracking-wide uppercase">
                                            Bot
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
