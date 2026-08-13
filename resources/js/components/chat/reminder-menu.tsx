import { AlarmClock } from 'lucide-react';

import { messageToolbarButton } from '@/components/chat/message-toolbar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslate } from '@/hooks/use-translate';

/**
 * The choices, in the order somebody scans them: the short ones first, because
 * "straks" is what most reminders are.
 *
 * The values are the server's vocabulary rather than minutes. Two of them are
 * not offsets at all — "morgenochtend" is nine o'clock on somebody's own clock,
 * not twenty-four hours from now — and working that out in the browser would
 * put the one authority on which timezone a member is in in the wrong place.
 */
const CHOICES = ['20m', '1h', '3h', 'tomorrow', 'next_week'] as const;

/**
 * "Herinner me hier straks aan", on one message.
 *
 * A menu rather than a button, because a reminder without a moment is not a
 * thing anybody can want. Nothing here says whether a reminder is already set:
 * picking again simply moves the one that exists, so a state to display would
 * be a state somebody has to reason about for no gain.
 */
export function ReminderMenu({
    onSelect,
}: {
    onSelect: (when: (typeof CHOICES)[number]) => void;
}) {
    const { t } = useTranslate();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    title={t('messages.actions.remind')}
                    aria-label={t('messages.actions.remind')}
                    className={messageToolbarButton()}
                >
                    <AlarmClock className="size-3.5" />
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end">
                <DropdownMenuLabel>
                    {t('messages.reminder.heading')}
                </DropdownMenuLabel>

                {CHOICES.map((choice) => (
                    <DropdownMenuItem
                        key={choice}
                        onSelect={() => onSelect(choice)}
                    >
                        {t(`messages.reminder.when.${choice}`)}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
