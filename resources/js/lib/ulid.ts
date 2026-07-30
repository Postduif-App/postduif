const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32
const TIME_LENGTH = 10;
const RANDOM_LENGTH = 16;

let lastTime = 0;
let lastRandom: number[] = [];

function encodeTime(now: number): string {
    let out = '';

    for (let i = TIME_LENGTH - 1; i >= 0; i--) {
        out = ENCODING[now % 32] + out;
        now = Math.floor(now / 32);
    }

    return out;
}

function randomBytes(): number[] {
    const values = new Uint8Array(RANDOM_LENGTH);
    crypto.getRandomValues(values);

    return Array.from(values, (byte) => byte % 32);
}

/**
 * Increment the random component so that two ULIDs minted in the same
 * millisecond still sort in creation order. Without this, messages typed in
 * quick succession could swap places on screen.
 */
function bumpRandom(previous: number[]): number[] {
    const next = [...previous];

    for (let i = RANDOM_LENGTH - 1; i >= 0; i--) {
        if (next[i] < 31) {
            next[i]++;

            return next;
        }

        next[i] = 0;
    }

    return randomBytes();
}

/**
 * Generate a monotonic ULID in the browser.
 *
 * The client mints the message id so it can render the message immediately and
 * recognise its own broadcast echo, rather than showing a duplicate.
 *
 * Lowercased to match Laravel's HasUlids, and that is not cosmetic. Ids are
 * compared as strings to decide what a member has already read, and in ASCII
 * every uppercase letter sorts before every lowercase one — so a single
 * uppercase id among lowercase ones compares as older than everything and
 * quietly breaks the read pointer.
 */
export function ulid(): string {
    const now = Date.now();

    lastRandom = now === lastTime ? bumpRandom(lastRandom) : randomBytes();
    lastTime = now;

    return (
        encodeTime(now) + lastRandom.map((value) => ENCODING[value]).join('')
    ).toLowerCase();
}
