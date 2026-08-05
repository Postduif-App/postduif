import { useEffect, useState } from 'react';

/**
 * A number of seconds as somebody reads it: "7u 45m", and "12m" for a morning
 * that has only just started.
 *
 * Here rather than on the screen that shows the hours, because the user menu
 * counts the same shift in the same words — and two spellings of "hoe lang ben
 * ik al bezig" in one application is one too many.
 */
export function spokenDuration(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);

    return hours === 0 ? `${minutes}m` : `${hours}u ${minutes % 60}m`;
}

/**
 * How long the shift that began at this moment has been running, counting.
 *
 * Measured from the moment itself on every tick rather than added up from the
 * number the server sent, so a tab left open overnight shows the truth instead
 * of its own arithmetic. Null means nothing is running, and then nothing ticks
 * either — the menu is on every screen, and a timer per page load that nobody
 * is looking at is a timer nobody asked for.
 */
export function useElapsed(startedAt: string | null): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (startedAt === null) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [startedAt]);

    if (startedAt === null) {
        return 0;
    }

    return Math.max(
        0,
        Math.floor((now - new Date(startedAt).getTime()) / 1000),
    );
}
