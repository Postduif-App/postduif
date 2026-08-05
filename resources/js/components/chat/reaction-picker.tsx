import { Search, SmilePlus } from 'lucide-react';
import { useState } from 'react';

import { CustomEmoji, ReactionEmoji } from '@/components/chat/custom-emoji';
import { messageToolbarButton } from '@/components/chat/message-toolbar';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCustomEmoji } from '@/hooks/use-custom-emoji';
import { useRecentEmoji } from '@/hooks/use-recent-emoji';
import { useTranslate } from '@/hooks/use-translate';
import { EMOJI_GROUPS, QUICK_EMOJI } from '@/lib/emoji';

/** How many emoji the quick row holds before you have to go searching. */
const QUICK_SLOTS = 8;

/**
 * Two layers, because they are two different jobs.
 *
 * The quick row is a dropdown: a handful of emoji, one click, gone again. It is
 * built on the menu primitive, which brings Escape, click-outside, arrow keys
 * and a portal — a panel nested in the scrolling message list would otherwise
 * clip at the pane edge.
 *
 * Searching the whole set is a command dialog instead, the same pattern as
 * message search. A search box inside a menu fights the menu: items grab focus
 * on hover, and the menu's own typeahead swallows the keystrokes.
 */
export function ReactionPicker({
    onSelect,
    label: given,
    triggerClassName,
}: {
    onSelect: (emoji: string) => void;
    /**
     * What the trigger says it does. Reacting is not the only use: the composer
     * opens the same picker to write one into a message, and "reageer met"
     * would be a lie there. Left out, it says so itself — which a default
     * parameter cannot do, because the wording comes from a hook.
     */
    label?: string;
    /**
     * How the trigger looks. Defaults to a message-toolbar button, which is
     * where this started; the composer passes its own so the picker sits in the
     * row of buttons beside the paperclip.
     */
    triggerClassName?: string;
}) {
    const { t } = useTranslate();
    const [browsing, setBrowsing] = useState(false);
    const [recent, remember] = useRecentEmoji();
    const { entries: custom } = useCustomEmoji();

    const label = given ?? t('chat_ui.reactions.pick');

    // What you last used, topped up with the defaults so the row is never thin.
    const quick = [
        ...recent,
        ...QUICK_EMOJI.filter((emoji) => !recent.includes(emoji)),
    ].slice(0, QUICK_SLOTS);

    const choose = (emoji: string) => {
        remember(emoji);
        onSelect(emoji);
        setBrowsing(false);
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger
                    title={label}
                    aria-label={label}
                    className={triggerClassName ?? messageToolbarButton()}
                >
                    <SmilePlus className="size-3.5" />
                </DropdownMenuTrigger>

                <DropdownMenuContent
                    align="end"
                    className="flex w-auto min-w-0 items-center gap-0.5 p-1"
                >
                    {quick.map((emoji) => (
                        <DropdownMenuItem
                            key={emoji}
                            onSelect={() => choose(emoji)}
                            aria-label={`${label}: ${emoji}`}
                            className="justify-center p-1.5 text-base leading-none"
                        >
                            {/*
                                What you last reached for may well be one of
                                this workspace's own, so the row draws whatever
                                the stored string turns out to be.
                            */}
                            <ReactionEmoji emoji={emoji} />
                        </DropdownMenuItem>
                    ))}

                    <DropdownMenuSeparator className="mx-0.5 h-6 w-px" />

                    <DropdownMenuItem
                        onSelect={() => setBrowsing(true)}
                        aria-label={t('chat_ui.reactions.search')}
                        title={t('chat_ui.reactions.search')}
                        className="justify-center p-1.5"
                    >
                        <Search className="size-3.5" />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {/*
                Only built once you ask for it. There is one picker per message
                row, so keeping the dialog and its couple of hundred items in
                every row's element tree would cost on every render of the list.
            */}
            {browsing && (
                <CommandDialog
                    open
                    onOpenChange={setBrowsing}
                    title={t('chat_ui.reactions.dialog_title')}
                    description={t('chat_ui.reactions.dialog_description')}
                >
                    <CommandInput
                        placeholder={t('chat_ui.reactions.placeholder')}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {t('chat_ui.reactions.none')}
                        </CommandEmpty>

                        {/*
                            This workspace's own, first. They are the ones
                            somebody came here for — the curated set below is
                            the same on every installation, and a logo nobody
                            else has is worth more than a second smiley.
                        */}
                        {custom.length > 0 && (
                            <CommandGroup
                                heading={t('chat_ui.reactions.custom')}
                            >
                                {custom.map((entry) => (
                                    <CommandItem
                                        key={`custom-${entry.name}`}
                                        value={`:${entry.name}: ${entry.name}`}
                                        onSelect={() =>
                                            choose(`:${entry.name}:`)
                                        }
                                    >
                                        <CustomEmoji entry={entry} />
                                        <span className="text-xs text-muted-foreground">
                                            :{entry.name}:
                                        </span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}

                        {EMOJI_GROUPS.map((group) => (
                            <CommandGroup
                                key={group.label}
                                heading={group.label}
                            >
                                {group.entries.map((entry) => (
                                    <CommandItem
                                        key={entry.emoji}
                                        // The keywords ride along in the value,
                                        // so the dialog's filter does the work.
                                        value={`${entry.emoji} ${entry.keywords.join(' ')}`}
                                        onSelect={() => choose(entry.emoji)}
                                    >
                                        <span className="text-base leading-none">
                                            {entry.emoji}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {entry.keywords.join(', ')}
                                        </span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </CommandDialog>
            )}
        </>
    );
}
