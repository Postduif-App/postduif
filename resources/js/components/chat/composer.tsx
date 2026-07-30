import { SendHorizonal } from 'lucide-react';
import { useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

interface ComposerProps {
    placeholder: string;
    disabled?: boolean;
    onSend: (body: string) => void;
    /** Called on every keystroke; the hook decides how often to actually emit. */
    onTyping?: () => void;
}

const MAX_ROWS_HEIGHT = 200;

export function Composer({
    placeholder,
    disabled = false,
    onSend,
    onTyping,
}: ComposerProps) {
    const [body, setBody] = useState('');
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const resize = () => {
        const textarea = textareaRef.current;

        if (textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = `${Math.min(textarea.scrollHeight, MAX_ROWS_HEIGHT)}px`;
        }
    };

    const submit = () => {
        const trimmed = body.trim();

        if (trimmed === '' || disabled) {
            return;
        }

        onSend(trimmed);
        setBody('');
        requestAnimationFrame(resize);
    };

    return (
        <div className="px-4 pt-1 pb-4">
            <div className="flex items-end gap-2 rounded-lg border bg-background p-2 transition-shadow focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/30">
                <textarea
                    ref={textareaRef}
                    value={body}
                    disabled={disabled}
                    rows={1}
                    placeholder={placeholder}
                    onChange={(event) => {
                        setBody(event.target.value);
                        resize();

                        if (event.target.value !== '') {
                            onTyping?.();
                        }
                    }}
                    onKeyDown={(event) => {
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
                nieuwe regel
            </p>
        </div>
    );
}
