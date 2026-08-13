import { describe, expect, it } from 'vitest';

import {
    HuddleMixer,
    recordingFilename,
    recordingMimeType,
} from '@/lib/huddle-mixer';

class FakeSource {
    connections = 0;

    connect(): void {
        this.connections++;
    }

    disconnect(): void {
        this.connections--;
    }
}

/** Enough of an AudioContext to see what the mixer wires up, and nothing more. */
function fakeContext() {
    const sources: FakeSource[] = [];
    const state = { closed: false };

    const context = {
        createMediaStreamDestination: () => ({ stream: {} }),
        createMediaStreamSource: () => {
            const source = new FakeSource();
            sources.push(source);

            return source;
        },
        close: () => {
            state.closed = true;

            return Promise.resolve();
        },
    };

    return { context: context as unknown as AudioContext, sources, state };
}

/** A stream carrying a given number of audio tracks. */
const streamWith = (audioTracks: number): MediaStream =>
    ({
        getAudioTracks: () => Array.from({ length: audioTracks }, () => ({})),
    }) as unknown as MediaStream;

describe('mixing a huddle into one stream', () => {
    it('wires a stream with audio into the mix', () => {
        const { context, sources } = fakeContext();
        const mixer = new HuddleMixer(context);

        mixer.add('self', streamWith(1));

        expect(mixer.size).toBe(1);
        expect(sources[0].connections).toBe(1);
    });

    /** A camera-only stream is an ordinary thing to be handed here. */
    it('ignores a stream with no audio rather than throwing on it', () => {
        const { context, sources } = fakeContext();
        const mixer = new HuddleMixer(context);

        mixer.add('camera', streamWith(0));

        expect(mixer.size).toBe(0);
        expect(sources).toHaveLength(0);
    });

    /** Somebody changing microphone halfway through a recording. */
    it('replaces what was under a name, unwiring the old one', () => {
        const { context, sources } = fakeContext();
        const mixer = new HuddleMixer(context);

        mixer.add('self', streamWith(1));
        mixer.add('self', streamWith(1));

        expect(mixer.size).toBe(1);
        expect(sources[0].connections).toBe(0);
        expect(sources[1].connections).toBe(1);
    });

    /** Somebody leaving the huddle while it is being recorded. */
    it('takes a stream back out again', () => {
        const { context, sources } = fakeContext();
        const mixer = new HuddleMixer(context);

        mixer.add('7', streamWith(1));
        mixer.remove('7');

        expect(mixer.size).toBe(0);
        expect(sources[0].connections).toBe(0);
    });

    it('says nothing about a name it does not know', () => {
        const { context } = fakeContext();
        const mixer = new HuddleMixer(context);

        expect(() => mixer.remove('nobody')).not.toThrow();
    });

    /*
     * Browsers allow only a handful of AudioContexts at a time, so one left
     * open per recording is how the fourth huddle of an afternoon quietly stops
     * being recordable.
     */
    it('closes the context when the mix is done with', () => {
        const { context, sources, state } = fakeContext();
        const mixer = new HuddleMixer(context);

        mixer.add('self', streamWith(1));
        mixer.add('7', streamWith(1));
        mixer.close();

        expect(mixer.size).toBe(0);
        expect(sources.every((source) => source.connections === 0)).toBe(true);
        expect(state.closed).toBe(true);
    });
});

describe('what this browser records into', () => {
    it('prefers Opus in WebM where it can have it', () => {
        expect(recordingMimeType(() => true)).toBe('audio/webm;codecs=opus');
    });

    /** Safari, which refuses WebM outright. */
    it('falls back to what the browser will take', () => {
        expect(recordingMimeType((type) => type === 'audio/mp4')).toBe(
            'audio/mp4',
        );
    });

    /** Then MediaRecorder picks for itself rather than being handed a lie. */
    it('gives no answer when the browser takes none of them', () => {
        expect(recordingMimeType(() => false)).toBeUndefined();
    });

    it('names the file after what was actually recorded', () => {
        expect(recordingFilename('audio/webm;codecs=opus')).toBe('huddle.webm');
        expect(recordingFilename('audio/mp4')).toBe('huddle.mp4');
        expect(recordingFilename('audio/ogg;codecs=opus')).toBe('huddle.ogg');
    });
});
