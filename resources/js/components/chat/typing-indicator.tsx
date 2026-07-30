import type { MessageAuthor } from '@/types/chat';

function describe(typing: MessageAuthor[]): string {
    const names = typing.map((user) => user.name.split(' ')[0]);

    if (names.length === 1) {
        return `${names[0]} is aan het typen`;
    }

    if (names.length === 2) {
        return `${names[0]} en ${names[1]} zijn aan het typen`;
    }

    return 'Meerdere mensen zijn aan het typen';
}

export function TypingIndicator({ typing }: { typing: MessageAuthor[] }) {
    // Reserve the line even when nobody is typing, so the composer does not
    // jump up and down as people start and stop.
    return (
        <p
            className="flex h-5 items-center gap-1.5 px-5 text-xs text-muted-foreground"
            aria-live="polite"
        >
            {typing.length > 0 && (
                <>
                    <span className="flex gap-0.5">
                        {[0, 1, 2].map((dot) => (
                            <span
                                key={dot}
                                className="size-1 animate-bounce rounded-full bg-muted-foreground/60"
                                style={{ animationDelay: `${dot * 150}ms` }}
                            />
                        ))}
                    </span>
                    {describe(typing)}
                </>
            )}
        </p>
    );
}
