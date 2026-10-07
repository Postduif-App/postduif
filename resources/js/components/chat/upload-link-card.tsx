import { Inbox, Lock } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { MessageUploadLinkCard } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

/** Why the link is in the conversation but no longer takes anything in. */
const DEAD: Record<
    Exclude<MessageUploadLinkCard['state'], 'usable'>,
    TranslationKey
> = {
    expired: 'components.upload_link_card.expired',
    revoked: 'components.upload_link_card.revoked',
    exhausted: 'components.upload_link_card.exhausted',
};

/**
 * What a link to one of our own upload links is for, under the message that
 * shared it.
 *
 * The sibling of TransferCard, drawn the same way: nothing was fetched, and a
 * closed link reads as closed before anybody clicks. What it never shows is who
 * already sent something in — this card is seen by the whole channel, often
 * with the customer reading along.
 */
export function UploadLinkCard({ card }: { card: MessageUploadLinkCard }) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();
    const dead = card.state !== 'usable';

    return (
        <a
            href={card.url}
            className={cn(
                'mt-1.5 flex max-w-lg items-center gap-3 rounded-lg border border-l-2 p-3 transition-colors hover:bg-muted/50',
                dead ? 'border-l-destructive/40' : 'border-l-primary/40',
            )}
        >
            <Inbox
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
                    {card.title}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {t('components.upload_link_card.kind')} ·{' '}
                    {dead
                        ? t(DEAD[card.state as keyof typeof DEAD])
                        : t('components.upload_link_card.open_until', {
                              date: formats.date.format(
                                  new Date(card.expiresAt),
                              ),
                          })}
                    {!dead &&
                        card.uploadsLeft !== null &&
                        ` · ${tChoice('components.upload_link_card.uploads_left', card.uploadsLeft)}`}
                </span>
            </span>

            {card.isLocked && !dead && (
                <Lock
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-label={t('components.upload_link_card.locked')}
                />
            )}
        </a>
    );
}
