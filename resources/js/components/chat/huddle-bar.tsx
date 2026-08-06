import {
    Headphones,
    Maximize2,
    Mic,
    MicOff,
    Minimize2,
    PhoneOff,
    Video,
    VideoOff,
} from 'lucide-react';

import { HuddleDeviceMenu } from '@/components/chat/huddle-device-menu';
import { HuddleTile } from '@/components/chat/huddle-tile';
import { Button } from '@/components/ui/button';
import type { HuddleControls } from '@/hooks/use-huddle';
import { MAX_CAMERAS, MAX_PARTICIPANTS } from '@/hooks/use-huddle';
import { useMediaDevices } from '@/hooks/use-media-devices';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { ActiveChannel } from '@/types/chat';

interface HuddleBarProps {
    channel: ActiveChannel;
    currentUserId: number;
    /**
     * Handed in rather than made here.
     *
     * The button that starts a huddle sits in the channel's header, a level
     * above this bar, and both have to drive the same microphone, the same
     * connections and the same way out. So the conversation owns the hook and
     * this draws what it says.
     */
    controls: HuddleControls;
    /** Whether the huddle has taken the conversation's room. */
    expanded: boolean;
    onToggleExpanded: () => void;
}

/**
 * Who is talking in this channel, and the way in or out.
 *
 * Under the header and above everything else, drawn only when there is
 * something to say: a huddle going on, or the right to start one. An empty
 * strip on every channel that has never held one would cost every conversation
 * a row of height for a feature most of them never use.
 */
export function HuddleBar({
    channel,
    currentUserId,
    controls,
    expanded,
    onToggleExpanded,
}: HuddleBarProps) {
    const { t, tChoice } = useTranslate();
    const {
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
    } = controls;

    /*
     * Only asked for once somebody is in a huddle: before permission is given
     * the browser hands back devices with empty labels, so a list built outside
     * one would be a row of blanks.
     */
    const devices = useMediaDevices(state === 'in');

    const live = channel.huddle !== null && participants.length > 0;

    /*
     * Only while something is actually going on — yours, or somebody else's you
     * could walk into. A channel where nobody is talking says nothing at all:
     * the way to start one is the button in the header, not a strip of chrome
     * on every conversation asking whether you would like to.
     */
    if (!live && state !== 'joining') {
        return null;
    }

    const inside = state === 'in';

    /** Only you in here, which is a different sentence entirely. */
    const alone =
        participants.length === 1 && participants[0]?.id === currentUserId;

    /*
     * Whether anybody is in shot. Below this the bar stays one line — a strip
     * of empty tiles under every audio huddle would cost the conversation a
     * hundred pixels to say nothing.
     */
    const showing = inside && !expanded && (camera || cameras.size > 0);

    return (
        <div
            className={cn(
                'shrink-0 border-b text-xs',
                inside && 'bg-primary/5',
            )}
        >
            <div className="flex flex-wrap items-center gap-2 px-4 py-2">
                <Headphones
                    className={cn(
                        'size-4 shrink-0',
                        live ? 'text-primary' : 'text-muted-foreground',
                    )}
                    aria-hidden="true"
                />

                {live ? (
                    <p className="min-w-0 text-muted-foreground">
                        {alone ? (
                            /*
                                Nobody to name yet. Reading your own name back
                                at you — "Sebastiaan Kloos is aan het praten" —
                                is the app telling you something you are in the
                                middle of doing.
                            */
                            t('chat_ui.huddle.alone')
                        ) : (
                            <>
                                {/*
                                    The names rather than a count: "Sanne en
                                    Joost zijn aan het praten" is what makes
                                    somebody walk in, and a number is not. The
                                    verb comes from tChoice, because one person
                                    talking is not "zijn".
                                */}
                                <span className="font-medium text-foreground">
                                    {participants
                                        .map((person) => person.name)
                                        .filter(Boolean)
                                        .join(', ')}
                                </span>{' '}
                                {tChoice(
                                    'chat_ui.huddle.talking',
                                    participants.length,
                                )}
                            </>
                        )}
                    </p>
                ) : (
                    <p className="text-muted-foreground">
                        {t('chat_ui.huddle.starting')}
                    </p>
                )}

                <div className="ml-auto flex shrink-0 items-center gap-1.5">
                    {cameraRefused && (
                        <span className="text-destructive">
                            {t('chat_ui.huddle.no_camera')}
                        </span>
                    )}

                    {state === 'refused' && (
                        <span className="text-destructive">
                            {t('chat_ui.huddle.no_microphone')}
                        </span>
                    )}

                    {state === 'full' && (
                        <span className="text-destructive">
                            {t('chat_ui.huddle.full', {
                                count: MAX_PARTICIPANTS,
                            })}
                        </span>
                    )}

                    {inside ? (
                        <>
                            {/*
                                One variant throughout, with the state in the
                                colour — the same way the header draws a
                                favourited channel or a failed scheduled
                                message. Swapping the variant made the button
                                change shape under the cursor, which reads as a
                                different button rather than the same one in
                                another state.

                                Red for muted because that is the surprising
                                one: the microphone is on when you walk in, and
                                the thing worth noticing is that you are not
                                being heard.
                            */}
                            <Button
                                size="sm"
                                variant="outline"
                                className={cn(
                                    'h-7 gap-1.5',
                                    muted &&
                                        'border-destructive/40 text-destructive hover:text-destructive',
                                )}
                                onClick={toggleMute}
                                aria-pressed={muted}
                            >
                                {muted ? (
                                    <MicOff className="size-3.5" />
                                ) : (
                                    <Mic className="size-3.5" />
                                )}
                                {muted
                                    ? t('chat_ui.huddle.unmute')
                                    : t('chat_ui.huddle.mute')}
                            </Button>

                            <HuddleDeviceMenu
                                label={t('chat_ui.huddle.pick_microphone')}
                                devices={devices.microphones}
                                onChoose={(id) => switchDevice('audio', id)}
                            />

                            {/*
                                And the accent for the camera, because here it
                                is the other way round: off is how you arrive,
                                so being in shot is the notable state.
                            */}
                            <Button
                                size="sm"
                                variant="outline"
                                className={cn(
                                    'h-7 gap-1.5',
                                    camera &&
                                        'border-primary/40 text-primary hover:text-primary',
                                )}
                                onClick={toggleCamera}
                                aria-pressed={camera}
                            >
                                {camera ? (
                                    <Video className="size-3.5" />
                                ) : (
                                    <VideoOff className="size-3.5" />
                                )}
                                {camera
                                    ? t('chat_ui.huddle.camera_off')
                                    : t('chat_ui.huddle.camera_on')}
                            </Button>

                            {/*
                                Also while the camera is off, which is the
                                moment somebody actually wants it: you pick
                                which camera they will see before you appear,
                                not after. The choice is remembered and used
                                when it goes on — see useHuddle.
                            */}
                            <HuddleDeviceMenu
                                label={t('chat_ui.huddle.pick_camera')}
                                devices={devices.cameras}
                                onChoose={(id) => switchDevice('video', id)}
                            />

                            {/*
                                Room to actually look at somebody. Beside the
                                camera rather than at the end: it belongs to
                                what you are watching, not to leaving.
                            */}
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-7 gap-1.5"
                                onClick={onToggleExpanded}
                                aria-pressed={expanded}
                            >
                                {expanded ? (
                                    <Minimize2 className="size-3.5" />
                                ) : (
                                    <Maximize2 className="size-3.5" />
                                )}
                                {expanded
                                    ? t('chat_ui.huddle.shrink')
                                    : t('chat_ui.huddle.expand')}
                            </Button>

                            <Button
                                size="sm"
                                variant="destructive"
                                className="h-7 gap-1.5"
                                onClick={leave}
                            >
                                <PhoneOff className="size-3.5" />
                                {t('chat_ui.huddle.leave')}
                            </Button>
                        </>
                    ) : (
                        channel.canHuddle && (
                            <Button
                                size="sm"
                                className="h-7"
                                disabled={state === 'joining'}
                                onClick={join}
                            >
                                {live
                                    ? t('chat_ui.huddle.join')
                                    : t('chat_ui.huddle.start')}
                            </Button>
                        )
                    )}
                </div>
            </div>

            {showing && (
                <div className="flex gap-2 overflow-x-auto px-4 pb-2">
                    {/* Yourself first, the way every other client puts it. */}
                    {camera && (
                        <HuddleTile
                            name={t('chat_ui.huddle.you')}
                            stream={ownCamera}
                            own
                        />
                    )}

                    {participants
                        .filter((person) => person.id !== currentUserId)
                        .filter((person) => cameras.has(person.id))
                        .map((person) => (
                            <HuddleTile
                                key={person.id}
                                name={person.name}
                                stream={cameras.get(person.id) ?? null}
                            />
                        ))}
                </div>
            )}

            {/*
            The mesh's honest limit. Everybody sends their picture to everybody
            else, so the cost of one more camera is one more upload for each
            person already in — which is where a huddle stops being cheap and
            an SFU would start earning its keep.
        */}
            {inside && cameras.size + (camera ? 1 : 0) > MAX_CAMERAS && (
                <p className="px-4 pb-2 text-destructive">
                    {t('chat_ui.huddle.too_many_cameras', {
                        count: MAX_CAMERAS,
                    })}
                </p>
            )}
        </div>
    );
}
