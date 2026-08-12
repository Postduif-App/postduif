import { FileSignature } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { MessageContractCard } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

/**
 * Why the contract is in the channel but no longer taking signatures.
 *
 * Keys rather than words: this is a module constant, and a hook cannot be
 * called from one — so the lookup happens where t() is in reach. The same shape
 * the secret card uses.
 */
const CLOSED: Record<string, TranslationKey> = {
    completed: 'chat_ui.contract.completed',
    cancelled: 'chat_ui.contract.cancelled',
    expired: 'chat_ui.contract.expired',
};

/**
 * A contract out for signature, under the message that shared it.
 *
 * Says how many of the people asked have signed, and nothing about which — that
 * would show the channel exactly who is holding things up, which is a thing to
 * say to somebody directly rather than in front of their colleagues.
 *
 * The link is the same for everybody and the server decides where it lands: a
 * signer who still has something to do goes to their own page, everybody else
 * to the contract. It cannot be decided here, because this card is drawn once
 * and broadcast to the whole channel at the same moment.
 */
export function ContractCard({ card }: { card: MessageContractCard }) {
    const formats = useFormats();
    const { t } = useTranslate();

    const closed = card.state !== 'sent' && card.state !== 'draft';
    const done = card.state === 'completed';

    return (
        <a
            href={card.url}
            className={cn(
                'mt-1.5 flex max-w-lg items-center gap-3 rounded-lg border border-l-2 p-3 transition-colors hover:bg-muted/50',
                done
                    ? 'border-l-emerald-500/50'
                    : closed
                      ? 'border-l-destructive/40'
                      : 'border-l-primary/40',
            )}
        >
            <FileSignature
                className={cn(
                    'size-5 shrink-0',
                    done
                        ? 'text-emerald-600'
                        : closed
                          ? 'text-destructive'
                          : 'text-muted-foreground',
                )}
            />

            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate text-sm font-medium',
                        /*
                         * Struck through for a contract that was withdrawn or
                         * ran out, and deliberately not for one that is
                         * finished: a signed contract is the opposite of
                         * cancelled, and the same styling for both would be the
                         * card saying so.
                         */
                        closed && !done && 'text-muted-foreground line-through',
                    )}
                >
                    {card.title}
                </span>

                <span className="block truncate text-xs text-muted-foreground">
                    {t('chat_ui.contract.signed', {
                        done: card.signedCount,
                        total: card.signerCount,
                    })}
                    {' · '}
                    {closed
                        ? t(CLOSED[card.state])
                        : card.state === 'draft'
                          ? t('chat_ui.contract.draft')
                          : card.expiresAt === null
                            ? t('chat_ui.contract.open')
                            : t('chat_ui.contract.until', {
                                  date: formats.date.format(
                                      new Date(card.expiresAt),
                                  ),
                              })}
                </span>
            </span>
        </a>
    );
}
