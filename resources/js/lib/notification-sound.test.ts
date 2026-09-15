import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

interface Ring {
    frequency: number;
    startedAt: number;
    stoppedAt: number;
    connectedToGain: boolean;
}

/**
 * Just enough WebAudio to answer the questions this module raises.
 *
 * The suite runs in node, which has no AudioContext at all, and a real one
 * would only prove that the browser makes sound. What is worth pinning down is
 * this module's own behaviour: that it rings twice, that it keeps one context
 * rather than opening one per notification, that it wakes a suspended one, and
 * that it stays quiet instead of throwing where audio is not on offer.
 */
class StubAudioContext {
    static created = 0;
    static rings: Ring[] = [];
    static last: StubAudioContext | null = null;

    state: 'running' | 'suspended' = 'running';
    currentTime = 10;
    destination = { id: 'destination' };
    resumed = 0;

    constructor() {
        StubAudioContext.created += 1;
        StubAudioContext.last = this;
    }

    resume(): Promise<void> {
        this.resumed += 1;
        this.state = 'running';

        return Promise.resolve();
    }

    createGain() {
        return {
            gain: {
                setValueAtTime: () => {},
                linearRampToValueAtTime: () => {},
                exponentialRampToValueAtTime: () => {},
            },
            connect: () => {},
        };
    }

    createOscillator() {
        const ring: Ring = {
            frequency: 0,
            startedAt: 0,
            stoppedAt: 0,
            connectedToGain: false,
        };

        StubAudioContext.rings.push(ring);

        return {
            type: 'sine',
            frequency: {
                setValueAtTime: (value: number) => {
                    ring.frequency = value;
                },
            },
            connect: () => {
                ring.connectedToGain = true;
            },
            start: (at: number) => {
                ring.startedAt = at;
            },
            stop: (at: number) => {
                ring.stoppedAt = at;
            },
        };
    }
}

/**
 * The module keeps its context in a module-level variable, so every test gets a
 * fresh copy of the module rather than a leftover context from the one before.
 */
async function load() {
    vi.resetModules();

    return import('@/lib/notification-sound');
}

function stubWindow(audio: unknown): void {
    Object.assign(globalThis, {
        window: { AudioContext: audio },
    });
}

describe('the notification chime', () => {
    beforeEach(() => {
        StubAudioContext.created = 0;
        StubAudioContext.rings = [];
        StubAudioContext.last = null;
        stubWindow(StubAudioContext);
    });

    afterEach(() => {
        Reflect.deleteProperty(globalThis, 'window');
    });

    it('rings two notes, the second after the first', async () => {
        const { playNotificationSound } = await load();

        playNotificationSound();

        expect(StubAudioContext.rings).toHaveLength(2);

        const [first, second] = StubAudioContext.rings;

        expect(second.frequency).toBeGreaterThan(first.frequency);
        expect(second.startedAt).toBeGreaterThan(first.startedAt);
        expect(first.stoppedAt).toBeGreaterThan(first.startedAt);
    });

    it('goes through a gain node rather than straight at the speakers', async () => {
        const { playNotificationSound } = await load();

        playNotificationSound();

        expect(
            StubAudioContext.rings.every((ring) => ring.connectedToGain),
        ).toBe(true);
    });

    it('keeps one context however often it rings', async () => {
        const { playNotificationSound } = await load();

        playNotificationSound();
        playNotificationSound();
        playNotificationSound();

        expect(StubAudioContext.created).toBe(1);
        expect(StubAudioContext.rings).toHaveLength(6);
    });

    it('wakes a context the autoplay policy left suspended', async () => {
        const { playNotificationSound } = await load();

        playNotificationSound();

        const context = StubAudioContext.last!;
        expect(context.resumed).toBe(0);

        // What a browser leaves behind when the page has not been clicked in.
        context.state = 'suspended';

        playNotificationSound();

        expect(context.resumed).toBe(1);
        expect(StubAudioContext.created).toBe(1);
    });

    it('stays silent where the browser has no WebAudio', async () => {
        stubWindow(undefined);

        const { playNotificationSound } = await load();

        expect(() => playNotificationSound()).not.toThrow();
        expect(StubAudioContext.rings).toHaveLength(0);
    });

    it('stays silent while rendering on the server', async () => {
        Reflect.deleteProperty(globalThis, 'window');

        const { playNotificationSound } = await load();

        expect(() => playNotificationSound()).not.toThrow();
        expect(StubAudioContext.rings).toHaveLength(0);
    });

    it('does not throw when the context refuses to make nodes', async () => {
        class BrokenContext extends StubAudioContext {
            createOscillator(): never {
                throw new Error('context is closed');
            }
        }

        stubWindow(BrokenContext);

        const { playNotificationSound } = await load();

        expect(() => playNotificationSound()).not.toThrow();
    });
});
