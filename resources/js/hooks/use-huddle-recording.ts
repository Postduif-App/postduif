import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { mutatingHeaders } from '@/lib/csrf';
import {
    HuddleMixer,
    recordingFilename,
    recordingMimeType,
} from '@/lib/huddle-mixer';
import { announce, stopped, store } from '@/routes/chat/huddles/recording';

/**
 * How often the recorder hands over what it has.
 *
 * Not for safety — a tab that dies takes the chunks with it either way — but
 * because a MediaRecorder left to itself holds the whole conversation as one
 * growing buffer, and an hour of it in one allocation is how a laptop with the
 * huddle in a background tab ends up killing that tab.
 */
const CHUNK_MS = 5_000;

export type RecordingState = 'idle' | 'recording' | 'saving' | 'failed';

export interface HuddleRecordingControls {
    state: RecordingState;
    /** Whether this browser can record at all. */
    supported: boolean;
    start: () => void;
    stop: () => void;
    /**
     * Put a stream into the mix under a name, replacing what was there.
     *
     * Called by useHuddle as things move: 'self' for the microphone, the member
     * id for everybody else. Always, not only while recording — the hook keeps
     * the list and only builds a mixer when somebody presses record.
     */
    attach: (key: string, stream: MediaStream) => void;
    detach: (key: string) => void;
}

interface Target {
    workspace: string;
    channel: number;
}

/**
 * Recording a huddle from inside it.
 *
 * The browser does this, not the server, and that is forced rather than chosen:
 * a mesh has no middle, so the only machine that ever hears the whole
 * conversation is one of the participants'. Everything here follows from that —
 * the mixing, the upload at the end, and above all the announcement at the
 * start.
 *
 * The announcement goes first and the recorder only starts if it succeeded.
 * Consent given after the fact is not consent, and a browser that recorded five
 * seconds before the channel was told has already recorded five seconds nobody
 * agreed to.
 */
export function useHuddleRecording(
    target: Target,
    huddleId: number | null,
): HuddleRecordingControls {
    const [state, setState] = useState<RecordingState>('idle');

    /** What would go into the mix, kept whether or not anything is recording. */
    const streams = useRef(new Map<string, MediaStream>());
    /** Only while recording; the AudioContext lives exactly as long as it does. */
    const mixer = useRef<HuddleMixer | null>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    /** When it started, on the browser's own monotonic clock. */
    const startedAt = useRef(0);
    /**
     * The huddle being recorded, remembered from the moment record was pressed.
     *
     * Not read off the prop at upload time: the upload happens after the
     * recorder stopped, which is often the same gesture as leaving, and by then
     * the prop may already be null or — worse — a different huddle in the same
     * channel. The file belongs to the conversation it was made in.
     */
    const recordingHuddle = useRef<number | null>(null);

    const supported = typeof MediaRecorder !== 'undefined';

    /** Take the notice down without an Inertia visit, which would cancel the upload. */
    const clearNotice = useCallback(() => {
        const huddle = recordingHuddle.current;

        if (huddle === null) {
            return;
        }

        void fetch(stopped.url({ ...target, huddle }), {
            method: 'DELETE',
            headers: mutatingHeaders(),
            keepalive: true,
        }).catch(() => undefined);
    }, [target]);

    const attach = useCallback((key: string, stream: MediaStream) => {
        streams.current.set(key, stream);
        mixer.current?.add(key, stream);
    }, []);

    const detach = useCallback((key: string) => {
        streams.current.delete(key);
        mixer.current?.remove(key);
    }, []);

    /** Send what was recorded, and let go of it either way. */
    const upload = useCallback(() => {
        const huddle = recordingHuddle.current;
        const type = recorder.current?.mimeType || 'audio/webm';
        const parts = chunks.current;

        chunks.current = [];
        recorder.current = null;
        mixer.current?.close();
        mixer.current = null;

        /*
         * The notice comes down here rather than when the upload lands. A
         * recording that has stopped has stopped, and leaving the indicator lit
         * for however long a half-hour file takes to travel would tell the
         * channel it is still being recorded when it is not.
         */
        clearNotice();
        recordingHuddle.current = null;

        const blob = new Blob(parts, { type });

        // Nothing came out: a recorder stopped in the same second it started,
        // or a browser that refused the mix. There is nothing to send and
        // nothing to apologise for.
        if (huddle === null || blob.size === 0) {
            setState('idle');

            return;
        }

        setState('saving');

        const body = new FormData();
        body.append('audio', blob, recordingFilename(type));
        body.append(
            'duration_seconds',
            String(
                Math.max(
                    1,
                    Math.round((performance.now() - startedAt.current) / 1000),
                ),
            ),
        );

        /*
         * fetch rather than Inertia's router, and that is not a style choice.
         * Stopping a recording is usually the same gesture as leaving, so the
         * upload starts in the same breath as the DELETE that takes this member
         * out of the huddle — and Inertia cancels a visit in flight when the
         * next one begins. One of the two would lose, at random.
         *
         * Nothing here needs a page back either: what the channel eventually
         * shows is the transcription, which arrives over the socket whenever
         * the queue gets to it.
         */
        const headers = new Headers(mutatingHeaders());
        // Deleted so the browser can set it with the multipart boundary, which
        // is the one header a hand-written value cannot get right.
        headers.delete('Content-Type');

        void fetch(store.url({ ...target, huddle }), {
            method: 'POST',
            headers,
            body,
            // The server answers with a redirect it has nowhere to send us; not
            // following it saves rendering a whole page to throw away.
            redirect: 'manual',
        })
            .then((response) =>
                setState(
                    response.ok || response.type === 'opaqueredirect'
                        ? 'idle'
                        : 'failed',
                ),
            )
            .catch(() => setState('failed'));
    }, [clearNotice, target]);

    const begin = useCallback(
        (huddle: number) => {
            let mixed: HuddleMixer;

            try {
                mixed = new HuddleMixer(new AudioContext());
            } catch {
                setState('failed');

                return;
            }

            streams.current.forEach((stream, key) => mixed.add(key, stream));

            /*
             * Nothing to record. Reached when the microphone was refused, which
             * is already its own message elsewhere — but a recorder started on
             * a silent mix would produce a file of nothing and call it a
             * meeting.
             */
            if (mixed.size === 0) {
                mixed.close();
                setState('failed');

                return;
            }

            const mimeType = recordingMimeType((type) =>
                MediaRecorder.isTypeSupported(type),
            );

            let made: MediaRecorder;

            try {
                made = new MediaRecorder(
                    mixed.stream,
                    mimeType ? { mimeType } : undefined,
                );
            } catch {
                mixed.close();
                setState('failed');

                return;
            }

            made.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    chunks.current.push(event.data);
                }
            };

            made.onstop = () => upload();

            mixer.current = mixed;
            recorder.current = made;
            chunks.current = [];
            startedAt.current = performance.now();
            recordingHuddle.current = huddle;

            made.start(CHUNK_MS);
            setState('recording');
        },
        [upload],
    );

    const start = useCallback(() => {
        // 'failed' is a state you may try again from — a refused AudioContext or
        // a dropped upload is not a verdict on the next attempt.
        if (
            !supported ||
            huddleId === null ||
            state === 'recording' ||
            state === 'saving'
        ) {
            return;
        }

        /*
         * The channel is told first, and the recorder only starts if that
         * worked. The server refuses when somebody else is already recording,
         * and a browser that had started anyway would be making a second
         * recording of a conversation whose notice names one person.
         */
        router.post(
            announce.url({ ...target, huddle: huddleId }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => begin(huddleId),
                onError: () => setState('failed'),
            },
        );
    }, [supported, huddleId, state, target, begin]);

    const stop = useCallback(() => {
        // The upload runs from onstop, so there is nothing to do here beyond
        // asking — and asking twice is what a double click is.
        if (recorder.current && recorder.current.state !== 'inactive') {
            recorder.current.stop();
        }
    }, []);

    /*
     * Leaving, closing the tab, or navigating away while recording.
     *
     * Whatever was captured is lost — there is nowhere to put it in the
     * milliseconds a page has left — but the notice must not outlive the
     * browser that put it up, or the channel is told a recording is running on
     * a machine that has gone.
     */
    useEffect(
        () => () => {
            if (recorder.current && recorder.current.state !== 'inactive') {
                recorder.current.onstop = null;
                recorder.current.stop();
            }

            mixer.current?.close();
            mixer.current = null;
            clearNotice();
            recordingHuddle.current = null;
        },
        [clearNotice],
    );

    return { state, supported, start, stop, attach, detach };
}
