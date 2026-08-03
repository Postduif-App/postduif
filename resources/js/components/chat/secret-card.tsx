import { KeyRound } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { MessageSecretCard } from '@/types/chat';

/** Why the request is in the channel but no longer taking answers. */
const CLOSED: Record<string, string> = {
    expired: 'verlopen',
    revoked: 'ingetrokken',
};

const DATE_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    day: 'numeric',
    month: 'long',
});

/**
 * A request for secrets, under the message that asked.
 *
 * Says how many of the keys are in, and nothing about which or by whom — that
 * would be announcing who holds which credential to everybody in the channel.
 * The count is what somebody reading needs: whether there is still something
 * for them to do.
 */
export function SecretCard({ card }: { card: MessageSecretCard }) {
    const closed = card.state !== 'open';
    const complete = card.answeredCount >= card.keyCount;

    return (
        <a
            href={card.url}
            className={cn(
                'mt-1.5 flex max-w-lg items-center gap-3 rounded-lg border border-l-2 p-3 transition-colors hover:bg-muted/50',
                closed ? 'border-l-destructive/40' : 'border-l-primary/40',
            )}
        >
            <KeyRound
                className={cn(
                    'size-5 shrink-0',
                    closed ? 'text-destructive' : 'text-muted-foreground',
                )}
            />

            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate text-sm font-medium',
                        closed && 'text-muted-foreground line-through',
                    )}
                >
                    {card.title}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {card.answeredCount} van {card.keyCount} ingevuld ·{' '}
                    {closed
                        ? CLOSED[card.state]
                        : complete
                          ? 'compleet'
                          : `tot ${DATE_FORMAT.format(new Date(card.expiresAt))}`}
                </span>
            </span>
        </a>
    );
}
