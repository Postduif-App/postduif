import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

import type { Availability } from '@/types/auth';

interface StatusPayload {
    userId: number;
    emoji: string | null;
    text: string | null;
    availability: Availability;
}

export interface LiveStatus {
    emoji: string | null;
    text: string | null;
    availability: Availability;
}

/**
 * Statuses that changed since this page was rendered, keyed by user id.
 *
 * The same shape as useSidebarActivity next door, and for the same reason: the
 * statuses that came with the page were right when it was rendered and wrong a
 * minute later, so this layers the changes on top and drops them the moment
 * fresh props arrive. The server stays the source of truth.
 *
 * A whole status per entry rather than a delta: emoji, text and availability
 * are set in one gesture and read together, and merging them field by field
 * would make "cleared" indistinguishable from "unchanged".
 */
export function useStatusActivity(
    currentUserId: number,
): Record<number, LiveStatus> {
    const [statuses, setStatuses] = useState<Record<number, LiveStatus>>({});

    useEcho<StatusPayload>(
        `App.Models.User.${currentUserId}`,
        '.status.changed',
        (payload) => {
            setStatuses((current) => ({
                ...current,
                [payload.userId]: {
                    emoji: payload.emoji,
                    text: payload.text,
                    availability: payload.availability,
                },
            }));
        },
        [],
    );

    useEffect(() => {
        // "finish" rather than "success": Inertia 3 dispatches only start,
        // progress and finish, so a listener on "success" is never called and
        // these would pile up forever.
        return router.on('finish', () => setStatuses({}));
    }, []);

    return statuses;
}
