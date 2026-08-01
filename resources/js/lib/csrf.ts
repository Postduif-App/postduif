/**
 * Headers for a mutating fetch() call.
 *
 * Everything else in this app mutates through Inertia's router, which handles
 * this itself. Webhooks are the exception: creating one answers with a token
 * that is shown exactly once, and an Inertia redirect has nowhere to put a
 * value that must not be flashed into the session.
 *
 * Laravel sets the XSRF-TOKEN cookie on every web response and accepts it back
 * as this header — the same handshake axios performs.
 */
export function mutatingHeaders(): HeadersInit {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(cookie
            ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.split('=')[1]) }
            : {}),
    };
}
