/**
 * What Echo's connector reports about the socket, in its own words.
 *
 * Five cases because that is what laravel-echo's type says, but the Reverb
 * connector only ever produces four of them: it folds pusher-js's "unavailable"
 * into "failed" and never says "reconnecting" at all. Handled anyway — the
 * driver is a line of config, not a law of nature.
 */
export type ConnectionStatus =
    'connected' | 'connecting' | 'disconnected' | 'reconnecting' | 'failed';

/**
 * Whether the socket is worth mentioning.
 *
 * 'none' is the point of this function: a chat that is talking to the server
 * should say nothing at all.
 */
export type ConnectionNotice = 'none' | 'offline';

/**
 * Whether this status should put a banner over the app.
 *
 * The awkward case is "connecting", which covers two different moments. On a
 * fresh page load it is the ordinary handshake, over in a moment, and a banner
 * for it would flash on every navigation — so it stays silent. After a socket
 * that was working drops, the same word means the app is not receiving
 * anything, which is exactly what somebody staring at a quiet channel needs to
 * be told. Hence `hasConnected`: the caller remembers whether this page ever
 * had a working socket, and that decides which of the two it is.
 *
 * "failed" is not silent even before the first connect, because the connector
 * only reaches it once pusher-js has run out of what it will try by itself.
 */
export function connectionNotice(
    status: ConnectionStatus,
    hasConnected: boolean,
): ConnectionNotice {
    switch (status) {
        case 'connected':
            return 'none';
        case 'connecting':
            return hasConnected ? 'offline' : 'none';
        case 'disconnected':
        case 'reconnecting':
        case 'failed':
            return 'offline';
    }
}
