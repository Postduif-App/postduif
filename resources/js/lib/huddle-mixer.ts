/**
 * One stream out of everybody's, so a huddle can be recorded at all.
 *
 * Huddles here are peer-to-peer: there is no server in the middle holding the
 * conversation, so nothing anywhere has a mix of it except the browsers, and
 * each of those has the pieces rather than the whole. A MediaRecorder takes one
 * stream, so somebody has to make one — which is what this is.
 *
 * WebAudio does the work: every incoming stream becomes a source node, all of
 * them are wired to a single destination node, and that node hands out a
 * MediaStream carrying the sum. No gain staging, no compression, no ducking —
 * the browsers already send levelled audio, and anything cleverer here would be
 * mixing decisions made blind for a conversation nobody is listening to.
 */
export class HuddleMixer {
    private readonly destination: MediaStreamAudioDestinationNode;

    /**
     * What is currently wired in, by the caller's own key.
     *
     * Keyed by a string the caller chooses — 'self' for the microphone, the
     * member id for everybody else — because the two things that move are a
     * peer arriving or leaving and this browser changing microphone, and both
     * are "replace what is under this name".
     */
    private readonly sources = new Map<string, MediaStreamAudioSourceNode>();

    constructor(private readonly context: AudioContext) {
        this.destination = context.createMediaStreamDestination();
    }

    /** The mix, as something a MediaRecorder will take. */
    get stream(): MediaStream {
        return this.destination.stream;
    }

    /**
     * Put a stream into the mix, replacing whatever was under this key.
     *
     * Streams without audio are ignored rather than refused: a camera-only
     * stream is an ordinary thing to be handed here, and createMediaStreamSource
     * throws on one.
     */
    add(key: string, stream: MediaStream): void {
        if (stream.getAudioTracks().length === 0) {
            return;
        }

        this.remove(key);

        const source = this.context.createMediaStreamSource(stream);
        source.connect(this.destination);
        this.sources.set(key, source);
    }

    /** Take one out again. Silent when there is nothing under that key. */
    remove(key: string): void {
        const source = this.sources.get(key);

        if (!source) {
            return;
        }

        source.disconnect();
        this.sources.delete(key);
    }

    /** How many streams are in the mix. */
    get size(): number {
        return this.sources.size;
    }

    /**
     * Take it all down.
     *
     * The AudioContext is closed, not merely disconnected: browsers allow only
     * a handful at a time, and one left open per recording would make the
     * fourth huddle of an afternoon fail to record for no visible reason.
     */
    close(): void {
        this.sources.forEach((source) => source.disconnect());
        this.sources.clear();
        void this.context.close().catch(() => undefined);
    }
}

/**
 * Which container this browser will actually record into.
 *
 * Asked rather than assumed, because the answer differs where it matters most:
 * Chrome and Firefox give Opus in WebM, Safari gives AAC in MP4 and refuses
 * WebM outright. Handing MediaRecorder a type it does not know throws, and the
 * fallback of passing nothing at all lets the browser pick — which is the right
 * last resort, not the right first choice, since the server has a list of
 * mimetypes it accepts.
 */
export function recordingMimeType(
    supported: (type: string) => boolean,
): string | undefined {
    const wanted = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/mp4',
        'audio/ogg;codecs=opus',
    ];

    return wanted.find(supported);
}

/**
 * What to call the uploaded file.
 *
 * Derived from the type the browser actually recorded rather than fixed at
 * .webm: the extension is what the server's mimetype rule is read against after
 * a round trip through a form, and a Safari recording called .webm is an MP4
 * lying about itself.
 */
export function recordingFilename(mimeType: string): string {
    const extension = mimeType.includes('mp4')
        ? 'mp4'
        : mimeType.includes('ogg')
          ? 'ogg'
          : 'webm';

    return `huddle.${extension}`;
}
