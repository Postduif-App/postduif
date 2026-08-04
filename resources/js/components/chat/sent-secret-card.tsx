import { router } from '@inertiajs/react';
import { Check, KeyRound } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { destroy } from '@/routes/chat/sent-secrets';
import type { MessageSentSecretCard } from '@/types/chat';

/**
 * A secret put aside for one person, under the message that announced it.
 *
 * Says who it is for and whether it is still there — never what it is, which is
 * not restraint but arithmetic: the server holds ciphertext it has no key for,
 * so there is nothing here that could be shown.
 *
 * Note that this card is not a way in, and cannot be. The key lives in the
 * fragment of a link that only the sender's browser ever held, so following this
 * without it lands on a page that says the link is incomplete. Everybody in the
 * channel sees the same card; only the person holding the real link can open
 * anything.
 */
export function SentSecretCard({
    card,
    workspaceSlug,
    currentUserId,
}: {
    card: MessageSentSecretCard;
    workspaceSlug: string;
    /** Whose screen this is, so only the sender is offered the way back. */
    currentUserId: number;
}) {
    const formats = useFormats();
    const { t } = useTranslate();
    const pending = card.state === 'pending';
    const mine = card.senderId === currentUserId;

    return (
        <div
            className={cn(
                'mt-1.5 flex max-w-lg items-center gap-3 rounded-lg border border-l-2 p-3',
                pending
                    ? 'border-l-primary/40'
                    : 'border-l-muted-foreground/30',
            )}
        >
            {card.state === 'revealed' ? (
                <Check className="size-5 shrink-0 text-muted-foreground" />
            ) : (
                <KeyRound
                    className={cn(
                        'size-5 shrink-0',
                        pending
                            ? 'text-muted-foreground'
                            : 'text-muted-foreground/60',
                    )}
                />
            )}

            <span className="min-w-0 flex-1">
                <span
                    className={cn(
                        'block truncate text-sm font-medium',
                        !pending && 'text-muted-foreground',
                    )}
                >
                    {card.label}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {t('chat_ui.sent_secret.for', {
                        name: card.recipientName,
                    })}
                    {' · '}
                    {card.state === 'revealed'
                        ? t('chat_ui.sent_secret.revealed')
                        : card.state === 'expired'
                          ? t('chat_ui.sent_secret.expired')
                          : t('chat_ui.sent_secret.expires', {
                                date: formats.date.format(
                                    new Date(card.expiresAt),
                                ),
                            })}
                </span>
            </span>

            {/*
                Only the sender, and only while there is still something to take
                back. Not a link to the secret: following it from here would be
                the recipient's one look being spent by somebody else.
            */}
            {mine && pending && (
                <button
                    type="button"
                    onClick={() => {
                        if (
                            window.confirm(
                                t('chat_ui.sent_secret.withdraw_confirm'),
                            )
                        ) {
                            router.delete(
                                destroy.url({
                                    workspace: workspaceSlug,
                                    sentSecret: card.id,
                                }),
                                { preserveScroll: true },
                            );
                        }
                    }}
                    className="shrink-0 rounded px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none"
                >
                    {t('chat_ui.sent_secret.withdraw')}
                </button>
            )}
        </div>
    );
}
