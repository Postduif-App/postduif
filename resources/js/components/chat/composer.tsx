import { SendHorizonal } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChannelMember } from '@/types/chat';

interface ComposerProps {
    placeholder: string;
    disabled?: boolean;
    members: ChannelMember[];
    onSend: (body: string) => void;
    /** Called on every keystroke; the hook decides how often to actually emit. */
    onTyping?: () => void;
}

const MAX_ROWS_HEIGHT = 200;
const MAX_SUGGESTIONS = 6;

/**
 * The "@handle" fragment directly left of the caret, or null.
 *
 * Anchored to a word boundary so an email address does not open the picker
 * halfway through typing it.
 */
function mentionQueryAt(value: string, caret: number): string | null {
    const match = value.slice(0, caret).match(/(?:^|\s)@([a-z0-9._-]*)$/i);

    return match ? match[1].toLowerCase() : null;
}

export function Composer({
    placeholder,
    disabled = false,
    members,
    onSend,
    onTyping,
}: ComposerProps) {
    const [body, setBody] = useState('');
    const [query, setQuery] = useState<string | null>(null);
    const [highlighted, setHighlighted] = useState(0);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const getInitials = useInitials();

    const suggestions =
        query === null
            ? []
            : members
                  .filter(
                      (member) =>
                          member.username.toLowerCase().includes(query) ||
                          member.name.toLowerCase().includes(query),
                  )
                  .slice(0, MAX_SUGGESTIONS);

    const resize = () => {
        const textarea = textareaRef.current;

        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = `${Math.min(textarea.scrollHeight, MAX_ROWS_HEIGHT)}px`;
        }
    };

    const complete = (member: ChannelMember) => {
        const textarea = textareaRef.current;
        const caret = textarea?.selectionStart ?? body.length;
        const before = body.slice(0, caret).replace(/@[a-z0-9._-]*$/i, '');
        const next = `${before}@${member.username} ${body.slice(caret)}`;

        setBody(next);
        setQuery(null);

        requestAnimationFrame(() => {
            resize();
            const position = before.length + member.username.length + 2;
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
        setQuery(null);
        requestAnimationFrame(resize);
    };

    return (
        <div className="relative px-4 pt-1 pb-4">
            {suggestions.length > 0 && (
                <ul
                    role="listbox"
                    aria-label="Vermeld een lid"
                    className="absolute bottom-full left-4 z-10 mb-1 w-72 overflow-hidden rounded-lg border bg-popover shadow-md"
                >
                    {suggestions.map((member, index) => (
                        <li key={member.id}>
                            <button
                                type="button"
                                role="option"
                                aria-selected={index === highlighted}
                                // The textarea must not lose focus, or the caret
                                // position we insert at is gone by the time the
                                // click handler runs.
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => complete(member)}
                                onMouseEnter={() => setHighlighted(index)}
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                    index === highlighted && 'bg-accent',
                                )}
                            >
                                <span className="flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold">
                                    {getInitials(member.name)}
                                </span>
                                <span className="truncate font-medium">
                                    {member.name}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    @{member.username}
                                </span>
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
                        setQuery(
                            mentionQueryAt(
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
                    onBlur={() => setQuery(null)}
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
                                setQuery(null);

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
                <kbd className="rounded bg-muted px-1 font-mono">@</kbd>{' '}
                vermeldt iemand
            </p>
        </div>
    );
}
