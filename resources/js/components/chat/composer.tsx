import { Hash, Lock, SendHorizonal } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChannelMember, ChannelSummary } from '@/types/chat';

interface ComposerProps {
    placeholder: string;
    disabled?: boolean;
    members: ChannelMember[];
    /** Channels the author may link to; the sidebar list is exactly this set. */
    channels: ChannelSummary[];
    onSend: (body: string) => void;
    /** Called on every keystroke; the hook decides how often to actually emit. */
    onTyping?: () => void;
}

const MAX_ROWS_HEIGHT = 200;
const MAX_SUGGESTIONS = 6;

/**
 * "@" picks a person, "#" picks a channel. Both behave identically from the
 * typist's side, so they share one picker rather than two that drift apart.
 */
const TRIGGERS = '@#';

/** Characters that may follow a trigger; covers both handles and slugs. */
const FRAGMENT = '[a-z0-9._-]*';

interface Suggestion {
    key: string;
    /** What gets written into the message, without the trigger character. */
    insert: string;
    primary: string;
    secondary?: string;
    icon: 'member' | 'public' | 'private';
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
function triggerAt(value: string, caret: number): ActiveTrigger | null {
    const match = value
        .slice(0, caret)
        .match(new RegExp(`(?:^|\\s)([${TRIGGERS}])(${FRAGMENT})$`, 'i'));

    return match ? { char: match[1], query: match[2].toLowerCase() } : null;
}

function SuggestionIcon({ icon, name }: { icon: Suggestion['icon']; name: string }) {
    const getInitials = useInitials();

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
    members,
    channels,
    onSend,
    onTyping,
}: ComposerProps) {
    const [body, setBody] = useState('');
    const [active, setActive] = useState<ActiveTrigger | null>(null);
    const [highlighted, setHighlighted] = useState(0);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const suggestions: Suggestion[] = (() => {
        if (active === null) {
            return [];
        }

        if (active.char === '@') {
            return members
                .filter(
                    (member) =>
                        member.username.toLowerCase().includes(active.query) ||
                        member.name.toLowerCase().includes(active.query),
                )
                .slice(0, MAX_SUGGESTIONS)
                .map((member) => ({
                    key: `member-${member.id}`,
                    insert: member.username,
                    primary: member.name,
                    secondary: `@${member.username}`,
                    icon: 'member' as const,
                }));
        }

        return channels
            .filter((channel) => (channel.name ?? '').includes(active.query))
            .slice(0, MAX_SUGGESTIONS)
            .map((channel) => ({
                key: `channel-${channel.id}`,
                insert: channel.name ?? '',
                primary: `#${channel.name}`,
                secondary: channel.isMember ? undefined : 'geen lid',
                icon: channel.type === 'private' ? ('private' as const) : ('public' as const),
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
        const next = `${before}${trigger}${suggestion.insert} ${body.slice(caret)}`;

        setBody(next);
        setActive(null);

        requestAnimationFrame(() => {
            resize();
            const position = before.length + suggestion.insert.length + 2;
            textarea?.focus();
            textarea?.setSelectionRange(position, position);
        });
    };

    const submit = () => {
        const trimmed = body.trim();

        if (trimmed === '' || disabled) {
            return;
        }

        onSend(trimmed);
        setBody('');
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

            <div className="flex items-end gap-2 rounded-lg border bg-background p-2 transition-shadow focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30">
                <textarea
                    ref={textareaRef}
                    value={body}
                    disabled={disabled}
                    rows={1}
                    placeholder={placeholder}
                    onChange={(event) => {
                        setBody(event.target.value);
                        setActive(
                            triggerAt(
                                event.target.value,
                                event.target.selectionStart,
                            ),
                        );
                        setHighlighted(0);
                        resize();

                        if (event.target.value !== '') {
                            onTyping?.();
                        }
                    }}
                    onBlur={() => setActive(null)}
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
                <Button
                    size="icon"
                    onClick={submit}
                    disabled={disabled || body.trim() === ''}
                    aria-label="Verstuur bericht"
                >
                    <SendHorizonal className="size-4" />
                </Button>
            </div>
            <p className="mt-1.5 px-1 text-xs text-muted-foreground">
                <kbd className="rounded bg-muted px-1 font-mono">Enter</kbd>{' '}
                verstuurt ·{' '}
                <kbd className="rounded bg-muted px-1 font-mono">
                    Shift+Enter
                </kbd>{' '}
                nieuwe regel ·{' '}
                <kbd className="rounded bg-muted px-1 font-mono">@</kbd> lid ·{' '}
                <kbd className="rounded bg-muted px-1 font-mono">#</kbd> kanaal
            </p>
        </div>
    );
}
