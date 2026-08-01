/**
 * Moving between what a datetime-local field shows and what the server stores.
 *
 * A datetime-local field has no timezone in it at all: it hands over a bare
 * wall clock, "2030-08-12T14:00", and it means that reading on the clock of
 * whoever is looking at it. The server stores instants, in UTC. Somewhere the
 * offset has to be applied, and doing it here — once, in the browser that knows
 * the offset — is the only place where it is known.
 *
 * Sending the field's raw value instead is the bug this exists to prevent: the
 * server parses it in its own timezone, so a member in Amsterdam who picks
 * 14:00 gets a message that goes out at 16:00 their time.
 */

/** The field's value as a real instant, for the server. */
export function fromLocalInput(value: string): string {
    return new Date(value).toISOString();
}

/**
 * An instant as the field wants it, in local time.
 *
 * Not toISOString(), which converts back to UTC: the field shows what it is
 * given, so an ISO string would move the time by the offset every time it is
 * opened.
 */
export function toLocalInput(iso: string): string {
    const when = new Date(iso);
    const pad = (value: number) => String(value).padStart(2, '0');

    return (
        `${when.getFullYear()}-${pad(when.getMonth() + 1)}-${pad(when.getDate())}` +
        `T${pad(when.getHours())}:${pad(when.getMinutes())}`
    );
}
