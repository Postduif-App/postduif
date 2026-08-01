import { cn } from '@/lib/utils';
import type { TicketPriority, TicketStatus } from '@/types/chat';

/**
 * The labels live here rather than travelling with every payload: they describe
 * a fixed set of values that the server validates against the same enum, and a
 * board of thirty rows would otherwise carry thirty copies of the same words.
 *
 * Colour carries meaning here, so it is never the only signal — every badge
 * spells out its status, and the palette only makes the three open ones easier
 * to tell apart at a glance.
 */
export const TICKET_STATUS: Record<
    TicketStatus,
    { label: string; description: string; className: string }
> = {
    open: {
        label: 'Open',
        description: 'Binnengekomen, nog niemand opgepakt.',
        className:
            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    in_progress: {
        label: 'In behandeling',
        description: 'Iemand is hiermee bezig.',
        className:
            'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    waiting: {
        label: 'Wacht op klant',
        description: 'De bal ligt bij de klant.',
        className:
            'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-400',
    },
    resolved: {
        label: 'Opgelost',
        description: 'Afgehandeld, wacht op bevestiging.',
        className:
            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    closed: {
        label: 'Gesloten',
        description: 'Definitief afgerond.',
        className: 'border-border bg-muted text-muted-foreground',
    },
};

export const TICKET_PRIORITY: Record<
    TicketPriority,
    { label: string; className: string }
> = {
    low: { label: 'Laag', className: 'text-muted-foreground' },
    normal: { label: 'Normaal', className: 'text-muted-foreground' },
    high: { label: 'Hoog', className: 'text-amber-600 dark:text-amber-400' },
    urgent: { label: 'Urgent', className: 'text-red-600 dark:text-red-400' },
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
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                TICKET_STATUS[status].className,
                className,
            )}
        >
            {TICKET_STATUS[status].label}
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
            {TICKET_PRIORITY[priority].label}
        </span>
    );
}
