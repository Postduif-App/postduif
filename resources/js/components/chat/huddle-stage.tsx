import { useState } from 'react';

import { HuddleTile } from '@/components/chat/huddle-tile';
import type { HuddleControls } from '@/hooks/use-huddle';
import { useTranslate } from '@/hooks/use-translate';

interface HuddleStageProps {
    currentUserId: number;
    controls: HuddleControls;
}

/**
 * The huddle, given the room the conversation usually has.
 *
 * One big picture with the rest as thumbnails under it, rather than a grid of
 * equal tiles. A grid is the right answer for a meeting where everybody takes
 * turns; a huddle is two or three people where one is talking and you want to
 * see them properly. The strip is how you change your mind about who that is.
 *
 * In the place of the message list rather than over the whole window, the same
 * way the board expands: the sidebar stays, so you can still see which channels
 * are asking for you and step out with one click.
 */
export function HuddleStage({ currentUserId, controls }: HuddleStageProps) {
    const { t } = useTranslate();
    const { participants, camera, ownCamera, cameras } = controls;

    /**
     * Who is on the big screen, when somebody has chosen.
     *
     * Null means nobody has, and then it falls to whoever is not you — the
     * person you came to look at. Held rather than derived so that somebody
     * else joining does not move the picture you were watching.
     */
    const [focused, setFocused] = useState<number | null>(null);

    const others = participants.filter((person) => person.id !== currentUserId);

    const onStage =
        participants.find((person) => person.id === focused) ??
        others[0] ??
        participants.find((person) => person.id === currentUserId);

    const streamFor = (id: number): MediaStream | null =>
        id === currentUserId
            ? camera
                ? ownCamera
                : null
            : (cameras.get(id) ?? null);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3 bg-muted/20 p-4">
            {/*
                The stage takes what is left after the strip, and the tile takes
                the stage — no centring wrapper, which would let the tile shrink
                to its content and leave the room around it empty.
            */}
            <div className="min-h-0 flex-1">
                {onStage && (
                    <HuddleTile
                        name={
                            onStage.id === currentUserId
                                ? t('chat_ui.huddle.you')
                                : onStage.name
                        }
                        stream={streamFor(onStage.id)}
                        own={onStage.id === currentUserId}
                        size="stage"
                    />
                )}
            </div>

            {/*
                Everybody, including whoever is on the stage: a strip that hides
                the one you are looking at makes the row jump every time you
                switch. Only drawn when there is more than one person, because
                a row of one thumbnail under your own face is furniture.
            */}
            {participants.length > 1 && (
                <div className="flex shrink-0 justify-center gap-2 overflow-x-auto">
                    {participants.map((person) => (
                        <HuddleTile
                            key={person.id}
                            name={
                                person.id === currentUserId
                                    ? t('chat_ui.huddle.you')
                                    : person.name
                            }
                            stream={streamFor(person.id)}
                            own={person.id === currentUserId}
                            focused={onStage?.id === person.id}
                            onFocus={() => setFocused(person.id)}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}
