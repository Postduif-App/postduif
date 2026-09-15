/**
 * The two notes that say "er is iets binnen".
 *
 * A rising pair rather than a single beep: one tone at this length is hard to
 * tell apart from the noises the rest of a desktop makes, where two notes a
 * fourth apart read as a signal from something. Quiet on purpose — this plays
 * while somebody is working in another window, so it has to be noticeable
 * without being startling.
 */
const TONES = [
    { frequency: 880, startsAt: 0 },
    { frequency: 1174.66, startsAt: 0.09 },
];

/** How long one note rings, envelope included. */
const TONE_SECONDS = 0.18;

/** Well under a system notification; a chime, not an alarm. */
const PEAK_GAIN = 0.06;

/** The rise and fall of one note, as a fraction of its length. */
const ATTACK_SECONDS = 0.01;

type AudioContextConstructor = new () => AudioContext;

/**
 * One context for the tab, made the first time something rings.
 *
 * Lazily rather than on import: a browser counts them, will not give a page
 * many, and a context created before anybody has clicked starts out suspended —
 * which is exactly the state we would rather not inherit.
 */
let shared: AudioContext | null = null;

function audioContext(): AudioContext | null {
    if (shared) {
        return shared;
    }

    if (typeof window === 'undefined') {
        return null;
    }

    const Constructor =
        window.AudioContext ??
        (window as Window & { webkitAudioContext?: AudioContextConstructor })
            .webkitAudioContext;

    if (!Constructor) {
        return null;
    }

    shared = new Constructor();

    return shared;
}

/**
 * Ring once, or stay silent — never throw.
 *
 * Everything here is best effort by design. Autoplay policy can refuse a
 * context that has not been unlocked by a click yet, an old browser may have no
 * WebAudio at all, and a machine can be out of audio hardware entirely. None of
 * those is a reason to break the screen the caller was drawing: a missed chime
 * costs nothing, because the badge beside it already says the same thing.
 */
export function playNotificationSound(): void {
    const context = audioContext();

    if (!context) {
        return;
    }

    try {
        // Suspended is the normal state after a page load until the person has
        // interacted with the page; by the time an inbox update arrives they
        // usually have, so this almost always succeeds.
        if (context.state === 'suspended') {
            Promise.resolve(context.resume()).catch(() => {
                // Still locked. The next chime tries again.
            });
        }

        for (const tone of TONES) {
            ring(context, tone.frequency, context.currentTime + tone.startsAt);
        }
    } catch {
        // A context the browser has closed under us, or a stub without the
        // nodes. Silence is the right failure here.
    }
}

/** One note, wired up and torn down by the clock rather than by hand. */
function ring(
    context: AudioContext,
    frequency: number,
    startsAt: number,
): void {
    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(frequency, startsAt);

    /*
     * An envelope rather than a bare on and off. A square edge on a sine is a
     * click — the discontinuity is broadband — and two clicks around a short
     * note is most of what you would hear.
     */
    gain.gain.setValueAtTime(0, startsAt);
    gain.gain.linearRampToValueAtTime(PEAK_GAIN, startsAt + ATTACK_SECONDS);
    gain.gain.exponentialRampToValueAtTime(0.0001, startsAt + TONE_SECONDS);

    oscillator.connect(gain);
    gain.connect(context.destination);

    oscillator.start(startsAt);
    oscillator.stop(startsAt + TONE_SECONDS);
}
