import { KeyRound } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { MessageSecretCard } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

/**
 * Why the request is in the channel but no longer taking answers.
 *
 * Keys rather than words: this is a module constant, and a hook cannot be
 * called from one — so the lookup happens where t() is in reach.
 */
const CLOSED: Record<string, TranslationKey> = {
    expired: 'chat_ui.secret.expired',
    revoked: 'chat_ui.secret.revoked',
};

/**
 * A request for secrets, under the message that asked.
 *
 * Says how many of the keys are in, and nothing about which or by whom — that
 * would be announcing who holds which credential to everybody in the channel.
 * The count is what somebody reading needs: whether there is still something
 * for them to do.
 */
export function SecretCard({ card }: { card: MessageSecretCard }) {
    const formats = useFormats();
    const { t } = useTranslate();
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
                    {t('chat_ui.secret.filled', {
                        done: card.answeredCount,
                        total: card.keyCount,
                    })}
                    {' · '}
                    {closed
                        ? t(CLOSED[card.state])
                        : complete
                          ? t('chat_ui.secret.complete')
                          : t('chat_ui.secret.until', {
                                date: formats.date.format(
                                    new Date(card.expiresAt),
                                ),
                            })}
                </span>
            </span>
        </a>
    );
}
