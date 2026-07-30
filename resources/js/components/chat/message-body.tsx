import { Link } from '@inertiajs/react';
import { Hash } from 'lucide-react';

import { cn } from '@/lib/utils';
import { show } from '@/routes/chat';
import type { ChannelMember, ChannelSummary, ChatWorkspace } from '@/types/chat';

/**
 * Same shape as the server-side parser in RecordMentions, so what lights up in
 * the UI is exactly what got a row in the mentions table. Two implementations
 * of one rule is a bug waiting to happen; keep them in step.
 *
 * "#" is matched here too, but only for display: a channel reference notifies
 * nobody, so there is nothing for the server to record.
 */
const REFERENCE_PATTERN = /(^|\s)([@#])([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)/gi;

interface MessageBodyProps {
    body: string;
    workspace: ChatWorkspace;
    members: ChannelMember[];
    /** Channels the reader may open; anything else stays plain text. */
    channels: ChannelSummary[];
    currentUsername?: string;
}

export function MessageBody({
    body,
    workspace,
    members,
    channels,
    currentUsername,
}: MessageBodyProps) {
    const byHandle = new Map(
        members.map((member) => [member.username.toLowerCase(), member]),
    );
    const bySlug = new Map(
        channels
            .filter((channel) => channel.name !== null)
            .map((channel) => [channel.name!.toLowerCase(), channel]),
    );

    const parts: React.ReactNode[] = [];
    let cursor = 0;

    for (const match of body.matchAll(REFERENCE_PATTERN)) {
        const [whole, lead, trigger, label] = match;

        if (match.index === undefined) {
            continue;
        }

        const member = trigger === '@' ? byHandle.get(label.toLowerCase()) : undefined;
        const channel = trigger === '#' ? bySlug.get(label.toLowerCase()) : undefined;

        // Unknown handle or a channel this reader cannot open? Then it is
        // ordinary text — never render a link that would only 403, and never
        // hint that a private channel exists.
        if (!member && !channel) {
            continue;
        }

        // The leading whitespace is part of the match but not of the reference.
        const start = match.index + lead.length;

        if (start > cursor) {
            parts.push(body.slice(cursor, start));
        }

        if (member) {
            parts.push(
                <span
                    key={`${start}-member`}
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
                    key={`${start}-channel`}
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
        }

        cursor = match.index + whole.length;
    }

    parts.push(body.slice(cursor));

    return <>{parts}</>;
}
