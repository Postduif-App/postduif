import { Lock, Package } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { MessageTransferCard } from '@/types/chat';

/** Why the link is on the list but no longer hands anything over. */
const DEAD: Record<string, string> = {
    expired: 'verlopen',
    revoked: 'ingetrokken',
    exhausted: 'opgebruikt',
};

const DATE_FORMAT = new Intl.DateTimeFormat('nl-NL', {
    day: 'numeric',
    month: 'long',
});

function humanSize(bytes: number): string {
    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit >= 2 && value < 100 ? 1 : 0).replace('.', ',')} ${units[unit]}`;
}

/**
 * What a link to one of our own transfers is carrying, under the message that
 * shared it.
 *
 * The sibling of LinkPreviewCard, and the differences are the interesting part.
 * Nothing here was fetched: the route is ours and the answer came out of our
 * own database, so there is no other site learning who is reading and no reason
 * for the feature to be behind a switch. It also says when a link has stopped
 * working, which a preview of somebody else's page could never know — that is
 * the whole reason to draw it rather than leave the bare token in the text.
 */
export function TransferCard({ card }: { card: MessageTransferCard }) {
    const dead = card.state !== 'usable';

    return (
        <a
            href={card.url}
            className={cn(
                'mt-1.5 flex max-w-lg items-center gap-3 rounded-lg border border-l-2 p-3 transition-colors hover:bg-muted/50',
                dead ? 'border-l-destructive/40' : 'border-l-primary/40',
            )}
        >
            <Package
                className={cn(
                    'size-5 shrink-0',
                    dead ? 'text-destructive' : 'text-muted-foreground',
                )}
            />

            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate text-sm font-medium',
                        dead && 'text-muted-foreground line-through',
                    )}
                >
                    {card.title ??
                        `${card.fileCount} ${card.fileCount === 1 ? 'bestand' : 'bestanden'}`}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {card.fileCount}{' '}
                    {card.fileCount === 1 ? 'bestand' : 'bestanden'} ·{' '}
                    {humanSize(card.size)} ·{' '}
                    {dead
                        ? DEAD[card.state]
                        : `tot ${DATE_FORMAT.format(new Date(card.expiresAt))}`}
                </span>
            </span>

            {/*
                Said on the card rather than left for the landing page: somebody
                who does not have the password should find that out before they
                click, not after.
            */}
            {card.isLocked && !dead && (
                <Lock
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-label="Met wachtwoord"
                />
            )}
        </a>
    );
}
