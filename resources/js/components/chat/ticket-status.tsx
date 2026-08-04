import {
    TICKET_PRIORITY_KEY,
    TICKET_STATUS_KEY,
} from '@/components/chat/ticket-panel';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { TicketPriority, TicketStatus } from '@/types/chat';

/**
 * What a status looks like. The words it is spelled with come out of
 * enums.php through TICKET_STATUS_KEY, so the badge and the panel that changes
 * it never disagree about what "waiting" is called.
 *
 * Colour carries meaning here, so it is never the only signal — every badge
 * spells out its status, and the palette only makes the three open ones easier
 * to tell apart at a glance.
 */
export const TICKET_STATUS: Record<TicketStatus, { className: string }> = {
    open: {
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    in_progress: {
        className:
            'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    waiting: {
        className:
            'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-400',
    },
    resolved: {
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    closed: {
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const TICKET_PRIORITY: Record<TicketPriority, { className: string }> = {
    low: { className: 'text-muted-foreground' },
    normal: { className: 'text-muted-foreground' },
    high: { className: 'text-amber-600 dark:text-amber-400' },
    urgent: { className: 'text-red-600 dark:text-red-400' },
};

/** The three statuses that still count as outstanding, in board order. */
export const OPEN_STATUSES: TicketStatus[] = ['open', 'in_progress', 'waiting'];

export const ALL_STATUSES: TicketStatus[] = [
    ...OPEN_STATUSES,
    'resolved',
    'closed',
];

export function TicketStatusBadge({
    status,
    className,
}: {
    status: TicketStatus;
    className?: string;
}) {
    const { t } = useTranslate();

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                TICKET_STATUS[status].className,
                className,
            )}
        >
            {t(TICKET_STATUS_KEY[status])}
        </span>
    );
}

/**
 * Priority is drawn as a word rather than a coloured dot alone: "hoog" and
 * "urgent" are the only two anyone acts on, and a dot needs a legend nobody
 * reads. Normal and low stay quiet on purpose.
 */
export function TicketPriorityLabel({
    priority,
}: {
    priority: TicketPriority;
}) {
    const { t } = useTranslate();

    if (priority === 'normal' || priority === 'low') {
        return null;
    }

    return (
        <span
            className={cn(
                'shrink-0 text-xs font-medium',
                TICKET_PRIORITY[priority].className,
            )}
        >
            {t(TICKET_PRIORITY_KEY[priority])}
        </span>
    );
}
