/**
 * What one browser tells another to get a connection up.
 *
 * A whisper goes to everybody on the channel's presence channel, not to one
 * peer — so every message names who it is for and everybody else drops it. See
 * forMe() below, which is the whole of that rule.
 */
export interface HuddleSignal {
    /** The member this is meant for. */
    to: number;
    /** The member it came from. */
    from: number;
    kind: 'offer' | 'answer' | 'candidate';
    /** An SDP, or an ICE candidate. Passed through to the browser untouched. */
    payload: unknown;
}

/**
 * Whether this signal is this member's business.
 *
 * Both halves are checked. Addressed to me is the obvious one; from somebody
 * else matters because a whisper is echoed to everybody subscribed, and a
 * browser that answered its own offer would negotiate with itself.
 */
export function forMe(signal: HuddleSignal, me: number): boolean {
    return signal.to === me && signal.from !== me;
}

/**
 * Whether this side gives way when both sides make an offer at once.
 *
 * Perfect negotiation needs exactly one polite peer per pair: when two offers
 * cross, the polite one rolls its own back and accepts the other, and the
 * impolite one ignores what came in. Decided by comparing member ids because
 * that is the one fact both browsers already agree on without asking anybody —
 * no round trip, no coin toss, and the same answer on both sides.
 */
export function isPolite(me: number, peer: number): boolean {
    return me < peer;
}
