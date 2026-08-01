import { useRef, useState } from 'react';

/**
 * Recording a voice note in the browser.
 *
 * MediaRecorder rather than anything uploaded live: a voice note is a file like
 * any other once it exists, and treating it as one means it travels through the
 * same validation, the same size limit and the same private disk as everything
 * else somebody sends.
 *
 * Nothing starts on its own. The microphone is asked for at the moment somebody
 * presses record, not on mount — a page that holds a live microphone because it
 * might need one later is a page nobody should have to trust.
 */
export type RecorderState = 'idle' | 'recording' | 'unavailable';

export interface VoiceRecorder {
    state: RecorderState;
    /** Seconds so far, for the counter beside the button. */
    seconds: number;
    start: () => Promise<void>;
    /** Hands back the recording, or null when it was cancelled or empty. */
    stop: () => Promise<File | null>;
    cancel: () => void;
}

/** Past this, it is a meeting rather than a note. */
const MAX_SECONDS = 300;

function supported(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof MediaRecorder !== 'undefined' &&
        navigator.mediaDevices?.getUserMedia !== undefined
    );
}

export function useVoiceRecorder(): VoiceRecorder {
    const [state, setState] = useState<RecorderState>(
        supported() ? 'idle' : 'unavailable',
    );
    const [seconds, setSeconds] = useState(0);

    const recorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const timerRef = useRef<number | null>(null);

    /**
     * Give the microphone back.
     *
     * Every track is stopped by hand: leaving one open keeps the browser's
     * recording indicator lit, which reads as "this page is still listening" —
     * and it would be right.
     */
    const release = () => {
        recorderRef.current?.stream
            .getTracks()
            .forEach((track) => track.stop());
        recorderRef.current = null;
        chunksRef.current = [];

        if (timerRef.current !== null) {
            window.clearInterval(timerRef.current);
            timerRef.current = null;
        }

        setSeconds(0);
    };

    const start = async () => {
        if (state !== 'idle') {
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const recorder = new MediaRecorder(stream);

            chunksRef.current = [];
            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);
                }
            };

            recorder.start();
            recorderRef.current = recorder;
            setState('recording');

            timerRef.current = window.setInterval(() => {
                setSeconds((current) => {
                    // Stopped rather than truncated: a note that silently loses
                    // its last minute is worse than one that ends when it said
                    // it would.
                    if (current + 1 >= MAX_SECONDS) {
                        recorderRef.current?.stop();
                    }

                    return current + 1;
                });
            }, 1000);
        } catch {
            // Refused, or no microphone. Either way there is nothing to record
            // with, and the button should stop offering.
            setState('unavailable');
        }
    };

    const stop = async (): Promise<File | null> => {
        const recorder = recorderRef.current;

        if (!recorder || state !== 'recording') {
            return null;
        }

        const file = await new Promise<File | null>((resolve) => {
            recorder.onstop = () => {
                const chunks = chunksRef.current;

                if (chunks.length === 0) {
                    resolve(null);

                    return;
                }

                const type = recorder.mimeType || 'audio/webm';
                const blob = new Blob(chunks, { type });

                // A name it can be recognised by in a list of files, and an
                // extension the server's mime check will agree with.
                resolve(
                    new File([blob], `spraakbericht-${Date.now()}.webm`, {
                        type,
                    }),
                );
            };

            recorder.stop();
        });

        release();
        setState('idle');

        return file;
    };

    const cancel = () => {
        recorderRef.current?.stop();
        release();
        setState('idle');
    };

    return { state, seconds, start, stop, cancel };
}
