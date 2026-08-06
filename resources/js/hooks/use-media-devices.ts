import { useCallback, useEffect, useState } from 'react';

export interface MediaDevice {
    id: string;
    label: string;
}

export interface MediaDevices {
    microphones: MediaDevice[];
    cameras: MediaDevice[];
}

/**
 * The microphones and cameras this browser will admit to having.
 *
 * Worth knowing about the timing: before permission is granted the browser
 * returns entries with empty labels — it will say how many devices there are
 * but not what they are called, because that alone is enough to tell one
 * machine from another. So this list is worth showing inside a huddle, where
 * permission has been given, and worth nothing outside one.
 *
 * Devices come and go while a call is running: a headset is plugged in, a
 * webcam is unplugged. The browser says so through devicechange, which is why
 * this listens rather than reading the list once.
 */
export function useMediaDevices(enabled: boolean): MediaDevices {
    const [devices, setDevices] = useState<MediaDevices>({
        microphones: [],
        cameras: [],
    });

    const read = useCallback(() => {
        if (!navigator.mediaDevices?.enumerateDevices) {
            return;
        }

        void navigator.mediaDevices
            .enumerateDevices()
            .then((found) => {
                const pick = (kind: MediaDeviceKind): MediaDevice[] =>
                    found
                        .filter((device) => device.kind === kind)
                        // An entry with no id is one the browser is refusing to
                        // identify; offering it would be offering a choice that
                        // cannot be acted on.
                        .filter((device) => device.deviceId !== '')
                        .map((device) => ({
                            id: device.deviceId,
                            label: device.label,
                        }));

                setDevices({
                    microphones: pick('audioinput'),
                    cameras: pick('videoinput'),
                });
            })
            .catch(() => undefined);
    }, []);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        read();

        const listener = () => read();

        navigator.mediaDevices?.addEventListener('devicechange', listener);

        return () => {
            navigator.mediaDevices?.removeEventListener(
                'devicechange',
                listener,
            );
        };
    }, [enabled, read]);

    return devices;
}
