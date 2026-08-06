import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { useHuddleSignals } from '@/hooks/use-huddle-signals';
import { mutatingHeaders } from '@/lib/csrf';
import type { HuddleSignal } from '@/lib/huddle-signalling';
import { isPolite } from '@/lib/huddle-signalling';
import { destroy, ping, store } from '@/routes/chat/huddles';
import type { ChatWorkspace, Huddle } from '@/types/chat';

/**
 * Above this many people a mesh stops being a huddle.
 *
 * Every browser holds a connection to every other, so eight people is
 * twenty-eight connections and eight upstreams of audio out of each laptop.
 * Past that the answer is a server that mixes, which is a different piece of
 * work — so this says no out loud rather than letting somebody's fan spin up
 * and their audio break.
 */
export const MAX_PARTICIPANTS = 8;

/**
 * And a lower one for cameras, because video is the expensive half.
 *
 * Audio at eight is a few hundred kilobits; eight cameras is each browser
 * uploading its picture seven times. Four is where a laptop still copes. Not
 * enforced by refusing — somebody's camera going on is not something to veto
 * halfway through a conversation — but said out loud, which is the point at
 * which a mixing server would start to earn its keep.
 */
export const MAX_CAMERAS = 4;

/**
 * How often a browser says it is still in the huddle.
 *
 * Three of these fit inside the sweeper's patience — see
 * SweepStaleHuddles::AFTER_SECONDS — so a tab that a browser throttled in the
 * background, or a laptop that slept for a moment, is not thrown out of a
 * conversation it is still in.
 */
const HEARTBEAT_MS = 30_000;

export type HuddleState = 'out' | 'joining' | 'in' | 'refused' | 'full';

export interface HuddleControls {
    state: HuddleState;
    /** Who is in it, as the server last said. */
    participants: Huddle['participants'];
    muted: boolean;
    /** Whether this browser is sending its camera. */
    camera: boolean;
    /** Whether asking for the camera was refused, or there is none. */
    cameraRefused: boolean;
    /** This browser's own camera stream, or null while it is off. */
    ownCamera: MediaStream | null;
    /** What the others are sending, keyed by member id. */
    cameras: Map<number, MediaStream>;
    join: () => void;
    leave: () => void;
    toggleMute: () => void;
    toggleCamera: () => void;
    /** Speak through another microphone, or look through another camera. */
    switchDevice: (kind: 'audio' | 'video', deviceId: string) => void;
}

/**
 * Being in a huddle: the microphone, the connections, and the way out.
 *
 * A mesh rather than a server that mixes. For the handful of people a huddle is
 * for that is less machinery, no media server to run, and — the part that
 * matters most here — audio that never passes through anything of ours.
 *
 * What this hook is careful about is that a connection is not state React can
 * re-render its way into: an RTCPeerConnection has to be made once, fed as
 * things arrive, and closed exactly once. All of it lives in refs, and what
 * comes out is only what the screen has to draw.
 */
export function useHuddle(
    workspace: ChatWorkspace,
    channelId: number,
    currentUserId: number,
    huddle: Huddle | null,
    iceServers: RTCIceServer[],
): HuddleControls {
    const [state, setState] = useState<HuddleState>('out');
    const [muted, setMuted] = useState(false);
    /** Whether this browser is sending its camera. Off on the way in, always. */
    const [camera, setCamera] = useState(false);
    /** Set when the camera was asked for and refused, or there is none. */
    const [cameraRefused, setCameraRefused] = useState(false);
    /**
     * The same stream as the ref below, kept a second time as state.
     *
     * Not duplication for its own sake: the ref is what the connections are fed
     * from, in callbacks that must not re-run when it changes, and this is what
     * the tile showing yourself renders from — which a ref cannot be, because
     * reading one during render is a component lying about being pure.
     */
    const [ownCamera, setOwnCamera] = useState<MediaStream | null>(null);
    /** What everybody else is sending, keyed by member id. */
    const [cameras, setCameras] = useState<Map<number, MediaStream>>(new Map());

    /** One connection per other member, keyed by their id. */
    const peers = useRef(new Map<number, RTCPeerConnection>());
    /** This browser's microphone, once somebody has allowed it. */
    const microphone = useRef<MediaStream | null>(null);
    /**
     * The camera, while it is on. A stream of its own rather than one asked for
     * alongside the microphone: joining must not depend on having a camera, and
     * a single getUserMedia({audio, video}) fails whole if either is refused.
     */
    const camcorder = useRef<MediaStream | null>(null);
    /** What is sending this browser's camera, per peer, so it can be taken back. */
    const videoSenders = useRef(new Map<number, RTCRtpSender>());
    /**
     * And what is sending the microphone, which is kept for a different reason:
     * swapping to another microphone replaces the track inside these rather
     * than adding one, so nothing has to be renegotiated.
     */
    const audioSenders = useRef(new Map<number, RTCRtpSender>());
    /** The audio elements playing what comes back, keyed the same way. */
    const speakers = useRef(new Map<number, HTMLAudioElement>());
    /**
     * Who this side is currently making an offer to.
     *
     * Perfect negotiation needs it: without it the polite peer cannot tell an
     * offer that crossed with its own from an ordinary one, because
     * setLocalDescription() has not settled yet and signalingState still reads
     * 'stable'. It would then give way to a collision that never happened.
     */
    const offering = useRef(new Set<number>());
    /**
     * Which microphone and camera this member picked, if they picked one.
     *
     * Kept because the choice outlives the track. Somebody chooses a camera
     * while it is off — which is the natural moment, before anybody sees you —
     * and the choice has to still be there when it goes on. A ref rather than
     * state: nothing on screen changes when it moves, and the only reader is
     * the getUserMedia call it feeds.
     */
    const preferred = useRef<{ audio: string | null; video: string | null }>({
        audio: null,
        video: null,
    });
    /*
     * The way out onto the wire, filled in below.
     *
     * A ref because of a knot that is real rather than accidental: signalling
     * needs a handler for what comes in, and that handler needs the way to
     * answer. One of the two has to be reachable before it exists, and a ref is
     * the smaller lie.
     */
    const sendRef = useRef<(signal: HuddleSignal) => void>(() => {});

    /*
     * Both memoised because effects below depend on them, and a fresh array or
     * object per render is a dependency that changed every render — which for
     * the offer effect means offering to everybody again, every time anybody
     * types.
     */
    const participants = useMemo(
        () => huddle?.participants ?? [],
        [huddle?.participants],
    );
    const inside = participants.some((person) => person.id === currentUserId);

    const target = useMemo(
        () => ({ workspace: workspace.slug, channel: channelId }),
        [workspace.slug, channelId],
    );

    /**
     * Take everything down.
     *
     * Called on leaving and on unmount, and safe to call twice: closing a
     * closed connection is a no-op, and the maps are emptied as they go.
     */
    const teardown = useCallback(() => {
        peers.current.forEach((peer) => peer.close());
        peers.current.clear();

        speakers.current.forEach((audio) => {
            audio.srcObject = null;
            audio.remove();
        });
        speakers.current.clear();

        // The track has to be stopped, not just dropped: an open microphone is
        // a recording light that stays on after somebody left the huddle.
        microphone.current?.getTracks().forEach((track) => track.stop());
        microphone.current = null;

        // And the camera, where the light is the only thing telling somebody
        // they are still in shot.
        camcorder.current?.getTracks().forEach((track) => track.stop());
        camcorder.current = null;
        videoSenders.current.clear();
        audioSenders.current.clear();
        setOwnCamera(null);
    }, []);

    useEffect(() => teardown, [teardown]);

    /** The connection to one member, made on first need. */
    const peerFor = useCallback(
        (id: number) => {
            const existing = peers.current.get(id);

            if (existing) {
                return existing;
            }

            const peer = new RTCPeerConnection({ iceServers });

            microphone.current?.getTracks().forEach((track) => {
                audioSenders.current.set(
                    id,
                    peer.addTrack(track, microphone.current as MediaStream),
                );
            });

            /*
             * Somebody who walks in while this browser's camera is already on
             * gets it from the start. Without this they would be the one person
             * in the huddle seeing a name where everybody else sees a face,
             * until the camera happened to be toggled again.
             */
            camcorder.current?.getVideoTracks().forEach((track) => {
                videoSenders.current.set(
                    id,
                    peer.addTrack(track, camcorder.current as MediaStream),
                );
            });

            /*
             * Something was added to this connection after it was up — a
             * camera going on, most likely. The browser says so here rather
             * than anywhere the caller could notice, so this is the only place
             * a second offer can come from.
             *
             * Guarded by `offering`, which is also what the other side's
             * collision check reads: making an offer is not instant, and
             * everything between here and setLocalDescription() settling is a
             * window another offer can arrive in.
             */
            peer.onnegotiationneeded = () => {
                void (async () => {
                    /*
                     * Deliberately not guarded on signalingState.
                     *
                     * An earlier version returned early unless the connection
                     * was settled, and that swallowed the case this whole
                     * feature is about. Somebody with their camera on answers a
                     * newcomer's audio-only offer: adding their video track
                     * asks for negotiation while the answer is still in flight,
                     * the guard dropped it, and nothing ever asked again — so
                     * the newcomer saw a black tile until they happened to
                     * switch on their own camera and forced a fresh round.
                     *
                     * The browser only raises this from a stable connection to
                     * begin with, and a genuine crossing is what `offering` and
                     * the politeness rule below are for. Turning that into a
                     * silent return was solving a problem the pattern already
                     * solves, by creating one it does not.
                     */
                    try {
                        offering.current.add(id);
                        await peer.setLocalDescription();

                        sendRef.current({
                            to: id,
                            from: currentUserId,
                            kind: 'offer',
                            payload: peer.localDescription?.toJSON(),
                        });
                    } catch {
                        // A connection that closed underneath us, or a state
                        // that moved on. The next change asks again.
                    } finally {
                        offering.current.delete(id);
                    }
                })();
            };

            peer.onicecandidate = (event) => {
                if (event.candidate) {
                    sendRef.current({
                        to: id,
                        from: currentUserId,
                        kind: 'candidate',
                        payload: event.candidate.toJSON(),
                    });
                }
            };

            /*
             * Sound and picture arrive on the same connection and are kept
             * apart here, because they are played by different things.
             *
             * Audio goes into an <audio> this hook owns: the browser mixes it,
             * and nothing on screen has to exist for somebody to be heard —
             * which matters, because a huddle is usable with the panel closed.
             *
             * Video cannot work that way. It has to end up in an element the
             * screen draws, so the stream is handed to React and the tile
             * attaches it.
             */
            peer.ontrack = (event) => {
                const [stream] = event.streams;

                if (event.track.kind === 'video') {
                    setCameras((current) => new Map(current).set(id, stream));

                    /*
                     * A camera going off arrives as the track ending rather
                     * than as a message of its own. Without this the last frame
                     * would sit there frozen, which reads as somebody staring.
                     */
                    event.track.onended = () => {
                        setCameras((current) => {
                            const next = new Map(current);
                            next.delete(id);

                            return next;
                        });
                    };

                    return;
                }

                let audio = speakers.current.get(id);

                if (!audio) {
                    audio = new Audio();
                    audio.autoplay = true;
                    speakers.current.set(id, audio);
                }

                audio.srcObject = stream;
            };

            peers.current.set(id, peer);

            return peer;
        },
        [currentUserId, iceServers],
    );

    const { send } = useHuddleSignals(
        channelId,
        currentUserId,
        useCallback(
            (signal: HuddleSignal) => {
                void (async () => {
                    const peer = peerFor(signal.from);

                    if (signal.kind === 'candidate') {
                        await peer
                            .addIceCandidate(
                                signal.payload as RTCIceCandidateInit,
                            )
                            // A candidate that arrives before the description
                            // it belongs to is normal, and not worth an error:
                            // the ones that matter come again.
                            .catch(() => undefined);

                        return;
                    }

                    const description =
                        signal.payload as RTCSessionDescriptionInit;

                    /*
                     * Perfect negotiation, the short version: if an offer
                     * arrives while this side is making one, only the polite
                     * peer gives way. Without this a pair that pressed join —
                     * or switched their camera on — at the same moment ends up
                     * with two half-finished negotiations and nothing working.
                     */
                    const collision =
                        signal.kind === 'offer' &&
                        (offering.current.has(signal.from) ||
                            peer.signalingState !== 'stable');

                    if (collision && !isPolite(currentUserId, signal.from)) {
                        // The impolite side simply carries on with its own.
                        return;
                    }

                    /*
                     * The polite side takes theirs instead. setRemoteDescription
                     * with an offer while one of ours is outstanding performs
                     * the rollback itself in a modern browser — no explicit
                     * setLocalDescription({type: 'rollback'}) needed.
                     */
                    await peer.setRemoteDescription(description);

                    if (signal.kind === 'offer') {
                        await peer.setLocalDescription();

                        sendRef.current({
                            to: signal.from,
                            from: currentUserId,
                            kind: 'answer',
                            payload: peer.localDescription?.toJSON(),
                        });
                    }
                })();
            },
            [currentUserId, peerFor],
        ),
        useCallback(() => {
            /*
             * The roster arrives as a prop reload from the server; nothing to
             * do here beyond letting the page re-render with it.
             *
             * The channel lists come along, and must: the sidebar draws a
             * headphone badge per channel from its own count, so asking for
             * `channel` alone left that badge lit after everybody had gone —
             * until the next page load happened to correct it. Same set as
             * useTicketActivity reloads, and for the second reason it gives
             * too: useSidebarActivity throws its accumulated unread deltas away
             * when any Inertia visit finishes, so a narrower reload would
             * quietly wipe the badges of every other channel.
             */
            router.reload({
                only: ['channel', 'channels', 'directMessages'],
            });
        }, []),
    );

    // And the knot from above, tied off: from here on the handler has a way to
    // answer.
    useEffect(() => {
        sendRef.current = send;
    }, [send]);

    /*
     * Open a connection to everybody who was already in when this browser
     * walked in.
     *
     * Only to the ones this side should call — the impolite peer of each pair —
     * so two people joining together do not both open the conversation.
     *
     * Note what this no longer does: make the offer. Adding the microphone in
     * peerFor() is a change to a fresh connection, so the browser asks for
     * negotiation by itself and the handler there sends it. One place offers
     * come from, which is what makes a camera going on later work the same way
     * as joining does.
     */
    useEffect(() => {
        if (state !== 'in') {
            return;
        }

        participants
            .filter((person) => person.id !== currentUserId)
            .filter((person) => !isPolite(currentUserId, person.id))
            .filter((person) => !peers.current.has(person.id))
            .forEach((person) => peerFor(person.id));

        // And close the ones who have gone, so their audio element and their
        // tile go with them.
        peers.current.forEach((peer, id) => {
            if (!participants.some((person) => person.id === id)) {
                peer.close();
                peers.current.delete(id);
                speakers.current.get(id)?.remove();
                speakers.current.delete(id);
                videoSenders.current.delete(id);
                audioSenders.current.delete(id);
                setCameras((current) => {
                    if (!current.has(id)) {
                        return current;
                    }

                    const next = new Map(current);
                    next.delete(id);

                    return next;
                });
            }
        });
    }, [state, participants, currentUserId, peerFor]);

    const join = useCallback(() => {
        if (state === 'joining' || state === 'in') {
            return;
        }

        if (participants.length >= MAX_PARTICIPANTS && !inside) {
            setState('full');

            return;
        }

        setState('joining');

        void (async () => {
            try {
                /*
                 * The microphone first, and the server second. Asking to be put
                 * in the list before knowing whether this browser can speak at
                 * all would show colleagues a name that never makes a sound.
                 */
                microphone.current = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                });
            } catch {
                // Refused, or no microphone at all. Either way there is nothing
                // to join with.
                setState('refused');

                return;
            }

            router.post(
                store.url(target),
                {},
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => setState('in'),
                    onError: () => {
                        teardown();
                        setState('out');
                    },
                },
            );
        })();
    }, [state, participants.length, inside, target, teardown]);

    const leave = useCallback(() => {
        teardown();
        setState('out');
        setMuted(false);
        setCamera(false);
        setCameraRefused(false);

        if (huddle) {
            router.delete(destroy.url({ ...target, huddle: huddle.id }), {
                preserveScroll: true,
                preserveState: true,
            });
        }
    }, [huddle, target, teardown]);

    /**
     * Put the camera on, or take it off again.
     *
     * Off on the way in and never otherwise: a huddle you fall into with your
     * face already showing is a meeting, and that is the thing this is not.
     *
     * Adding the track to each connection is all that is needed to make it
     * travel — the browser then asks for a new negotiation by itself, which the
     * handler in peerFor() answers. Taking it away is the same in reverse.
     */
    const toggleCamera = useCallback(() => {
        void (async () => {
            if (camera) {
                peers.current.forEach((peer, id) => {
                    const sender = videoSenders.current.get(id);

                    if (sender) {
                        peer.removeTrack(sender);
                        videoSenders.current.delete(id);
                    }
                });

                /*
                 * Stopped rather than disabled, unlike muting. A disabled video
                 * track leaves the camera light on, and that light is the only
                 * thing telling somebody whether they are still in shot — it
                 * has to mean what it says.
                 */
                camcorder.current?.getTracks().forEach((track) => track.stop());
                camcorder.current = null;
                setOwnCamera(null);
                setCamera(false);

                return;
            }

            try {
                camcorder.current = await navigator.mediaDevices.getUserMedia({
                    // The one they picked, or whatever the browser thinks is
                    // sensible. `exact` on purpose: falling back silently to a
                    // different camera than the one somebody chose is worse
                    // than not starting.
                    video: preferred.current.video
                        ? { deviceId: { exact: preferred.current.video } }
                        : true,
                });
            } catch {
                // Refused, or no camera. The huddle carries on with sound —
                // which is why this was never asked for at the door.
                setCameraRefused(true);

                return;
            }

            const track = camcorder.current.getVideoTracks()[0];

            if (!track) {
                setCameraRefused(true);

                return;
            }

            peers.current.forEach((peer, id) => {
                videoSenders.current.set(
                    id,
                    peer.addTrack(track, camcorder.current as MediaStream),
                );
            });

            setOwnCamera(camcorder.current);
            setCameraRefused(false);
            setCamera(true);
        })();
    }, [camera]);

    /*
     * Say we are still here, and say goodbye properly on the way out.
     *
     * Both halves are needed and neither is enough. The beat covers a browser
     * that dies without a word — crashed, shut, out of signal — which is the
     * case that otherwise leaves a channel stuck with a huddle nobody is in.
     * The goodbye covers the ordinary one, and covers it immediately: waiting
     * ninety seconds for the sweeper to notice a closed tab would leave
     * colleagues talking to somebody who has visibly gone.
     */
    useEffect(() => {
        if (state !== 'in' || !huddle) {
            return;
        }

        const target = {
            workspace: workspace.slug,
            channel: channelId,
            huddle: huddle.id,
        };

        const beat = window.setInterval(() => {
            void fetch(ping.url(target), {
                method: 'PATCH',
                headers: mutatingHeaders(),
                // A beat that fails is a beat: the next one is in half a
                // minute, and the sweeper's patience covers three of them.
            }).catch(() => undefined);
        }, HEARTBEAT_MS);

        const goodbye = () => {
            /*
             * keepalive, because this fires while the page is being torn down
             * and an ordinary fetch is cancelled with it. sendBeacon would do
             * the same but only speaks POST, and this route is a DELETE.
             */
            void fetch(destroy.url(target), {
                method: 'DELETE',
                headers: mutatingHeaders(),
                keepalive: true,
            }).catch(() => undefined);
        };

        /*
         * pagehide rather than beforeunload: it fires for a tab going into the
         * back/forward cache as well, which beforeunload does not, and it is
         * the one event mobile Safari reliably delivers when an app is closed.
         */
        window.addEventListener('pagehide', goodbye);

        return () => {
            window.clearInterval(beat);
            window.removeEventListener('pagehide', goodbye);
        };
    }, [state, huddle, workspace.slug, channelId]);

    /**
     * Speak through another microphone, or look through another camera.
     *
     * replaceTrack() rather than removing and adding: it swaps what a sender is
     * sending without touching the description either side agreed on, so
     * nothing is renegotiated and nobody's audio drops while it happens. It is
     * the one thing in here that is genuinely cheap.
     *
     * The old track is stopped afterwards, never before — stopping it first
     * would put a gap in what the others hear, and for a camera it would leave
     * the light on the device you just switched away from.
     */
    const switchDevice = useCallback(
        (kind: 'audio' | 'video', deviceId: string) => {
            void (async () => {
                /*
                 * Remembered first, and whatever happens next. Choosing a
                 * camera that is switched off is the ordinary case — you pick
                 * before you appear — and there is nothing to swap then, but
                 * the choice still has to hold.
                 */
                preferred.current = { ...preferred.current, [kind]: deviceId };

                const senders =
                    kind === 'audio'
                        ? audioSenders.current
                        : videoSenders.current;

                // Nothing is sending this kind yet: the choice is recorded and
                // takes effect when it goes on.
                if (senders.size === 0) {
                    return;
                }

                let stream: MediaStream;

                try {
                    stream = await navigator.mediaDevices.getUserMedia(
                        kind === 'audio'
                            ? { audio: { deviceId: { exact: deviceId } } }
                            : { video: { deviceId: { exact: deviceId } } },
                    );
                } catch {
                    // Unplugged between listing and choosing, or refused. The
                    // one that was working carries on.
                    return;
                }

                const track =
                    kind === 'audio'
                        ? stream.getAudioTracks()[0]
                        : stream.getVideoTracks()[0];

                if (!track) {
                    return;
                }

                /*
                 * A fresh track arrives enabled. Somebody who was muted and
                 * changes microphone did not thereby unmute themselves, and
                 * finding out otherwise takes a colleague telling you.
                 */
                if (kind === 'audio') {
                    track.enabled = !muted;
                }

                await Promise.all(
                    Array.from(senders.values()).map((sender) =>
                        sender.replaceTrack(track).catch(() => undefined),
                    ),
                );

                const previous =
                    kind === 'audio' ? microphone.current : camcorder.current;

                if (kind === 'audio') {
                    microphone.current = stream;
                } else {
                    camcorder.current = stream;
                    setOwnCamera(stream);
                }

                previous?.getTracks().forEach((old) => old.stop());
            })();
        },
        [muted],
    );

    const toggleMute = useCallback(() => {
        setMuted((current) => {
            const next = !current;

            /*
             * The track is disabled rather than removed. Removing it would
             * renegotiate with everybody in the huddle, which is a lot of
             * machinery for a button people press twice a minute — and the
             * connection stays up either way, so nothing is being sent.
             */
            microphone.current
                ?.getAudioTracks()
                .forEach((track) => (track.enabled = !next));

            return next;
        });
    }, []);

    return {
        state,
        participants,
        muted,
        camera,
        cameraRefused,
        ownCamera,
        cameras,
        join,
        leave,
        toggleMute,
        toggleCamera,
        switchDevice,
    };
}
