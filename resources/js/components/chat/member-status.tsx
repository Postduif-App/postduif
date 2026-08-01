import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { Availability } from '@/types/auth';

const DOT_CLASSES: Record<Availability, string> = {
    available: 'bg-emerald-500',
    away: 'bg-amber-500',
    'do-not-disturb': 'bg-rose-500',
};

const DOT_LABELS: Record<Availability, string> = {
    available: 'Beschikbaar',
    away: 'Afwezig',
    'do-not-disturb': 'Niet storen',
};

/**
 * Somebody's status emoji, with the text behind it.
 *
 * Only the emoji is shown inline. A status is context, not content — a line of
 * text next to every name would push the conversation itself off to the side —
 * and the words are one hover away for whoever wants them.
 *
 * Renders nothing at all when there is no status, rather than an empty space
 * held open: a list where most people have none should not look like a list of
 * gaps.
 */
export function MemberStatus({
    emoji,
    text,
    className,
}: {
    emoji: string | null;
    text: string | null;
    className?: string;
}) {
    if (!emoji && !text) {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn('cursor-default text-xs', className)}
                    aria-label={text ?? undefined}
                >
                    {emoji || '💬'}
                </span>
            </TooltipTrigger>
            <TooltipContent>{text ?? 'Heeft een status'}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The availability dot that sits on an avatar.
 *
 * Left out entirely for plain "available": it is the state almost everybody is
 * in almost always, so drawing it would mean a dot on every avatar in the
 * product carrying no information at all. The dot appearing is the signal.
 */
export function AvailabilityDot({
    availability,
    className,
}: {
    availability: Availability;
    className?: string;
}) {
    // Also nothing when the value is missing altogether: not every list that
    // draws a member carries their status, and a dot in a colour nobody set
    // would be worse than no dot.
    if (!availability || availability === 'available') {
        return null;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className={cn(
                        'block size-2.5 rounded-full ring-2 ring-background',
                        DOT_CLASSES[availability],
                        className,
                    )}
                    aria-label={DOT_LABELS[availability]}
                />
            </TooltipTrigger>
            <TooltipContent>{DOT_LABELS[availability]}</TooltipContent>
        </Tooltip>
    );
}
