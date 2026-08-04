import { MessageSquare, Plus } from 'lucide-react';
import { useState } from 'react';

import { TICKET_STATUS_KEY } from '@/components/chat/ticket-panel';
import {
    ALL_STATUSES,
    OPEN_STATUSES,
    TicketPriorityLabel,
    TicketStatusBadge,
} from '@/components/chat/ticket-status';
import { Button } from '@/components/ui/button';
import { useFormats } from '@/hooks/use-formats';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { TicketBoard as Board, TicketStatus } from '@/types/chat';

interface TicketBoardProps {
    board: Board;
    /** The ticket open in the panel, so its row can be marked as such. */
    activeNumber: number | null;
    canCreate: boolean;
    onOpen: (number: number) => void;
    onCreate: () => void;
}

/**
 * A filter is either one status, or the three that count as outstanding.
 *
 * "Openstaand" is the default rather than "alles": the question this board
 * exists to answer is what is still open, and a list that starts with every
 * ticket ever closed buries that under history.
 */
type Filter = TicketStatus | 'outstanding';

export function TicketBoard({
    board,
    activeNumber,
    canCreate,
    onOpen,
    onCreate,
}: TicketBoardProps) {
    const formats = useFormats();
    const { t } = useTranslate();
    const [filter, setFilter] = useState<Filter>('outstanding');

    const count = (status: TicketStatus) => board.counts[status] ?? 0;
    const outstanding = OPEN_STATUSES.reduce(
        (total, status) => total + count(status),
        0,
    );

    const rows = board.rows.filter((ticket) =>
        filter === 'outstanding'
            ? OPEN_STATUSES.includes(ticket.status)
            : ticket.status === filter,
    );

    const filters: { value: Filter; label: string; total: number }[] = [
        {
            value: 'outstanding',
            label: t('panelen.tickets.outstanding'),
            total: outstanding,
        },
        ...ALL_STATUSES.map((status) => ({
            value: status,
            label: t(TICKET_STATUS_KEY[status]),
            total: count(status),
        })),
    ];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            {/*
                One row, always. Opening a ticket narrows the board, and a
                wrapping header answered that by pushing "Nieuw ticket" onto a
                line of its own — so the button moved every time somebody
                clicked a ticket. The filters give way instead: they scroll
                sideways when there is not enough room, and the button stays
                where it was.
            */}
            <div className="flex items-center gap-2 border-b px-4 py-3">
                <div className="scrollbar-none flex min-w-0 flex-1 items-center gap-2 overflow-x-auto">
                    {filters.map((option) => {
                        const selected = filter === option.value;

                        return (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => setFilter(option.value)}
                                className={cn(
                                    'shrink-0 rounded-full border px-3 py-1 text-xs font-medium whitespace-nowrap transition-colors',
                                    selected
                                        ? 'border-primary/50 bg-primary/10 text-foreground'
                                        : 'text-muted-foreground hover:bg-muted',
                                )}
                            >
                                {option.label}
                                <span className="ml-1.5 tabular-nums opacity-70">
                                    {option.total}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {canCreate && (
                    <Button size="sm" className="shrink-0" onClick={onCreate}>
                        <Plus className="size-4" />
                        {t('panelen.tickets.new')}
                    </Button>
                )}
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {rows.length === 0 ? (
                    <p className="p-8 text-center text-sm text-muted-foreground">
                        {filter === 'outstanding'
                            ? t('chat_ui.tickets.none_outstanding')
                            : t('chat_ui.tickets.none_with_status')}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {rows.map((ticket) => (
                            <li key={ticket.id}>
                                <button
                                    type="button"
                                    onClick={() => onOpen(ticket.number)}
                                    aria-current={
                                        ticket.number === activeNumber
                                    }
                                    className={cn(
                                        'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50',
                                        ticket.number === activeNumber &&
                                            'bg-muted',
                                    )}
                                >
                                    <span className="w-10 shrink-0 pt-0.5 text-xs text-muted-foreground tabular-nums">
                                        #{ticket.number}
                                    </span>

                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2">
                                            <span className="truncate text-sm font-medium">
                                                {ticket.title}
                                            </span>
                                            <TicketPriorityLabel
                                                priority={ticket.priority}
                                            />
                                        </span>
                                        <span className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                            <span className="truncate">
                                                {ticket.opener?.name ??
                                                    t('panelen.ticket.unknown')}
                                                {ticket.createdAt &&
                                                    ` · ${formats.shortDate.format(new Date(ticket.createdAt))}`}
                                            </span>
                                            {ticket.commentCount > 0 && (
                                                <span className="flex shrink-0 items-center gap-1">
                                                    <MessageSquare className="size-3" />
                                                    {ticket.commentCount}
                                                </span>
                                            )}
                                        </span>
                                    </span>

                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        <TicketStatusBadge
                                            status={ticket.status}
                                        />
                                        {ticket.assignee && (
                                            <span className="text-xs text-muted-foreground">
                                                {ticket.assignee.name}
                                            </span>
                                        )}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
