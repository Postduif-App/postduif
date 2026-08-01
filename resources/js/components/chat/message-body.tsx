import { Link } from '@inertiajs/react';
import { Hash, Megaphone, Ticket as TicketIcon } from 'lucide-react';

import { parseInline } from '@/lib/inline-markdown';
import type { InlineNode } from '@/lib/inline-markdown';
import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import { BROADCAST_HANDLES } from '@/types/chat';
import type {
    ChannelMember,
    ChannelSummary,
    ChatWorkspace,
} from '@/types/chat';

/**
 * Same shape as the server-side parser in RecordMentions, so what lights up in
 * the UI is exactly what got a row in the mentions table. Two implementations
 * of one rule is a bug waiting to happen; keep them in step.
 *
 * "#" is matched here too, but only for display: a channel reference notifies
 * nobody, so there is nothing for the server to record.
 */
const REFERENCE_PATTERN = /(^|\s)([@#])([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)/gi;

/** "#12" — a ticket in the channel being read, the way people write it. */
const TICKET_NUMBER = /^[0-9]+$/;

interface MessageBodyProps {
    body: string;
    workspace: ChatWorkspace;
    members: ChannelMember[];
    /** Channels the reader may open; anything else stays plain text. */
    channels: ChannelSummary[];
    /**
     * The channel whose tickets a "#12" in this text refers to, or null when
     * the channel keeps none — then it stays plain text.
     */
    ticketChannelId?: number | null;
    currentUsername?: string;
}

/** Everything the reference pass needs to tell a link from ordinary text. */
interface ReferenceContext {
    workspace: ChatWorkspace;
    byHandle: Map<string, ChannelMember>;
    bySlug: Map<string, ChannelSummary>;
    ticketChannelId?: number | null;
    currentUsername?: string;
}

export function MessageBody({
    body,
    workspace,
    members,
    channels,
    ticketChannelId,
    currentUsername,
}: MessageBodyProps) {
    const context: ReferenceContext = {
        workspace,
        byHandle: new Map(
            members.map((member) => [member.username.toLowerCase(), member]),
        ),
        bySlug: new Map(
            channels
                .filter((channel) => channel.name !== null)
                .map((channel) => [channel.name!.toLowerCase(), channel]),
        ),
        ticketChannelId,
        currentUsername,
    };

    // Two passes, in this order. Formatting first, because its markers wrap
    // whole phrases the author typed; mentions and channel references are then
    // resolved inside each run of plain text that comes out. The other way
    // round, a mention already turned into an element would hide the text a
    // marker needs to wrap.
    return <>{renderNodes(parseInline(body), context, '')}</>;
}

function renderNodes(
    nodes: InlineNode[],
    context: ReferenceContext,
    path: string,
): React.ReactNode[] {
    return nodes.map((node, index) => {
        const key = `${path}${index}`;

        if (node.type === 'text') {
            return (
                <span key={key}>
                    {renderReferences(node.value, context, key)}
                </span>
            );
        }

        const children = renderNodes(node.children, context, `${key}-`);

        if (node.type === 'strong') {
            return <strong key={key}>{children}</strong>;
        }

        if (node.type === 'em') {
            return <em key={key}>{children}</em>;
        }

        // Struck-through text is text somebody took back, so it steps down a
        // little rather than staying as loud as what still counts.
        return (
            <s key={key} className="opacity-70">
                {children}
            </s>
        );
    });
}

function renderReferences(
    body: string,
    {
        workspace,
        byHandle,
        bySlug,
        ticketChannelId,
        currentUsername,
    }: ReferenceContext,
    path: string,
): React.ReactNode[] {
    const parts: React.ReactNode[] = [];
    let cursor = 0;

    for (const match of body.matchAll(REFERENCE_PATTERN)) {
        const [whole, lead, trigger, label] = match;

        if (match.index === undefined) {
            continue;
        }

        const handle = label.toLowerCase();
        const broadcast =
            trigger === '@' &&
            (BROADCAST_HANDLES as readonly string[]).includes(handle);
        const member = trigger === '@' ? byHandle.get(handle) : undefined;
        const channel =
            trigger === '#' ? bySlug.get(label.toLowerCase()) : undefined;

        // A channel by that name wins over a ticket number. Only one of the two
        // can be right, and a channel someone deliberately named "12" is a place
        // people navigate to, while the ticket is still reachable from the board.
        const ticket =
            trigger === '#' &&
            !channel &&
            ticketChannelId != null &&
            TICKET_NUMBER.test(label)
                ? { number: Number(label), channelId: ticketChannelId }
                : undefined;

        // Unknown handle or a channel this reader cannot open? Then it is
        // ordinary text — never render a link that would only 403, and never
        // hint that a private channel exists.
        if (!member && !channel && !broadcast && ticket === undefined) {
            continue;
        }

        // The leading whitespace is part of the match but not of the reference.
        const start = match.index + lead.length;

        if (start > cursor) {
            parts.push(body.slice(cursor, start));
        }

        if (broadcast) {
            // Everyone in the room is addressed, so it always concerns the
            // reader — hence the same treatment as a mention of you by name.
            parts.push(
                <span
                    key={`${path}-${start}-broadcast`}
                    className="inline-flex items-center gap-0.5 rounded bg-amber-400/25 px-1 py-0.5 text-sm font-medium text-amber-900 dark:text-amber-200"
                >
                    <Megaphone className="size-3" />@{handle}
                </span>,
            );
        } else if (member) {
            parts.push(
                <span
                    key={`${path}-${start}-member`}
                    title={member.name}
                    className={cn(
                        'rounded px-1 py-0.5 text-sm font-medium',
                        label.toLowerCase() === currentUsername?.toLowerCase()
                            ? 'bg-amber-400/25 text-amber-900 dark:text-amber-200'
                            : 'bg-primary/10 text-primary',
                    )}
                >
                    @{member.name}
                </span>,
            );
        } else if (channel) {
            parts.push(
                <Link
                    key={`${path}-${start}-channel`}
                    href={show({
                        workspace: workspace.slug,
                        channel: channel.id,
                    })}
                    className="inline-flex items-center gap-0.5 rounded bg-primary/10 px-1 py-0.5 text-sm font-medium text-primary hover:underline"
                >
                    <Hash className="size-3" />
                    {channel.name}
                </Link>,
            );
        } else if (ticket !== undefined) {
            // Opens the board with the ticket panel on it, the same URL the
            // board itself navigates to — so a bot announcement, a link someone
            // pasted and a click on the board all land in one place.
            parts.push(
                <Link
                    key={`${path}-${start}-ticket`}
                    href={show(
                        {
                            workspace: workspace.slug,
                            channel: ticket.channelId,
                        },
                        { query: { view: 'tickets', ticket: ticket.number } },
                    )}
                    preserveScroll
                    className="inline-flex items-center gap-0.5 rounded bg-primary/10 px-1 py-0.5 text-sm font-medium text-primary hover:underline"
                >
                    <TicketIcon className="size-3" />
                    {ticket.number}
                </Link>,
            );
        }

        cursor = match.index + whole.length;
    }

    parts.push(body.slice(cursor));

    return parts;
}
