import { Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat/forms';
import type { MessageFormCard } from '@/types/chat';
import type { TranslationKey } from '@/types/translations';

/**
 * Which way of being shut it was. Keys rather than words, because a module
 * constant cannot call a hook — the lookup happens where t() is in reach.
 */
const SHUT: Record<string, TranslationKey> = {
    closed: 'forms.card.closed',
    expired: 'forms.card.expired',
};

/**
 * A form somebody put in this channel.
 *
 * The opposite of the poll card beside it, and deliberately so. A poll shows
 * who voted for what; a form exists so that answers go to one named person, and
 * this card is drawn from a payload broadcast to the whole room. So it says
 * nothing about the answers — not the values, not who sent one in, not even how
 * many there are. Whether *you* already filled it in is a question the fill
 * screen can afford to answer, because that one is rendered per person.
 *
 * What is left is enough to decide whether to walk in: the title, the
 * explanation, how many questions, and whether it still takes any.
 */
export function FormCard({
    card,
    workspaceSlug,
}: {
    card: MessageFormCard;
    workspaceSlug: string;
}) {
    const { t, tChoice } = useTranslate();
    const formats = useFormats();
    const shut = card.state !== 'open';
    const empty = card.fieldCount === 0;

    return (
        <div
            className={cn(
                'mt-1.5 max-w-lg rounded-lg border border-l-2 p-3',
                shut ? 'border-l-destructive/40' : 'border-l-primary/40',
            )}
        >
            <div className="flex items-start gap-2">
                <ClipboardList
                    className={cn(
                        'mt-0.5 size-4 shrink-0',
                        shut ? 'text-destructive' : 'text-muted-foreground',
                    )}
                />
                <p
                    className={cn(
                        'min-w-0 flex-1 text-sm font-medium',
                        shut && 'text-muted-foreground line-through',
                    )}
                >
                    {card.title}
                </p>
            </div>

            {card.description && (
                <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                    {card.description}
                </p>
            )}

            <p className="mt-2 text-xs text-muted-foreground">
                {/*
                    An empty form says so instead of counting to nothing: "0
                    vragen" reads as a mistake, which is exactly what it is, and
                    the maker is the one who has to fix it.
                */}
                {empty
                    ? t('forms.card.empty')
                    : tChoice('forms.card.questions', card.fieldCount)}
                {shut && ` · ${t(SHUT[card.state])}`}
                {!shut && card.closesAt && (
                    <>
                        {' · '}
                        {t('forms.fill.closes_on', {
                            date: formats.date.format(new Date(card.closesAt)),
                        })}
                    </>
                )}
            </p>

            {/*
                No button on a form that cannot take an answer. The line above
                already said why, and a button that leads to a page saying the
                same thing is a second thing to read for nothing.
            */}
            {card.isFillable && !empty && (
                <Link
                    href={show.url({
                        workspace: workspaceSlug,
                        form: card.id,
                    })}
                    className="mt-2 inline-flex items-center rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted/50"
                >
                    {t('forms.card.fill')}
                </Link>
            )}
        </div>
    );
}
