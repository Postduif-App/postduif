import { cn } from '@/lib/utils';
import type { ChannelMember } from '@/types/chat';

/**
 * Same shape as the server-side parser in RecordMentions, so what lights up in
 * the UI is exactly what got a row in the mentions table. Two implementations
 * of one rule is a bug waiting to happen; keep them in step.
 */
const MENTION_PATTERN = /@([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)/gi;

interface MessageBodyProps {
    body: string;
    members: ChannelMember[];
    currentUsername?: string;
}

export function MessageBody({
    body,
    members,
    currentUsername,
}: MessageBodyProps) {
    const byHandle = new Map(
        members.map((member) => [member.username.toLowerCase(), member]),
    );

    const parts: React.ReactNode[] = [];
    let cursor = 0;

    for (const match of body.matchAll(MENTION_PATTERN)) {
        const handle = match[1].toLowerCase();
        const member = byHandle.get(handle);

        // Not a channel member? Then it is ordinary text — an email address or
        // a stray "@" should never render as a mention.
        if (!member || match.index === undefined) {
            continue;
        }

        if (match.index > cursor) {
            parts.push(body.slice(cursor, match.index));
        }

        parts.push(
            <span
                key={`${match.index}-${handle}`}
                title={member.name}
                className={cn(
                    'rounded px-1 py-0.5 text-sm font-medium',
                    handle === currentUsername?.toLowerCase()
                        ? 'bg-amber-400/25 text-amber-900 dark:text-amber-200'
                        : 'bg-primary/10 text-primary',
                )}
            >
                @{member.name}
            </span>,
        );

        cursor = match.index + match[0].length;
    }

    parts.push(body.slice(cursor));

    return <>{parts}</>;
}
