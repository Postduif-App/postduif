import { useEffect, useRef } from 'react';

import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

interface HuddleTileProps {
    name: string | null;
    /** What to draw, or null for somebody with their camera off. */
    stream: MediaStream | null;
    /** Your own tile: mirrored, and silent. */
    own?: boolean;
    /**
     * How big, and therefore where. 'strip' is the row of thumbnails, 'stage'
     * is the one being looked at.
     */
    size?: 'strip' | 'stage';
    /** Put this one on the stage. Absent where there is nothing to switch to. */
    onFocus?: () => void;
    /** Whether this is the one currently on the stage. */
    focused?: boolean;
}

/**
 * One person in a huddle, as a picture or as their initials.
 *
 * The stream is attached in an effect rather than passed as a prop, because
 * srcObject is not an attribute — React has no way to set it, and a src it
 * could set would need a blob URL that then has to be revoked.
 */
export function HuddleTile({
    name,
    stream,
    own = false,
    size = 'strip',
    onFocus,
    focused = false,
}: HuddleTileProps) {
    const video = useRef<HTMLVideoElement>(null);
    const getInitials = useInitials();

    useEffect(() => {
        const element = video.current;

        if (!element) {
            return;
        }

        element.srcObject = stream;

        // Dropped on the way out, so a stream that has ended is not held alive
        // by an element nobody is looking at any more.
        return () => {
            element.srcObject = null;
        };
    }, [stream]);

    return (
        <div
            /*
                A button only when there is somewhere to go. A tile that looks
                pressable and does nothing is worse than one that plainly is
                not — and on the stage there is nothing to switch to.
            */
            onClick={onFocus}
            role={onFocus ? 'button' : undefined}
            tabIndex={onFocus ? 0 : undefined}
            onKeyDown={
                onFocus
                    ? (event) => {
                          if (event.key === 'Enter' || event.key === ' ') {
                              event.preventDefault();
                              onFocus();
                          }
                      }
                    : undefined
            }
            className={cn(
                'relative overflow-hidden rounded-lg border',
                size === 'stage'
                    ? /*
                       * Fills whatever the stage gives it. Black rather than
                       * the muted grey a thumbnail uses: what is left over
                       * when the picture's shape does not match the window
                       * should read as letterbox, the way it does in every
                       * other video call, instead of as an empty page with
                       * something small in the middle.
                       */
                      'h-full w-full bg-black'
                    : 'aspect-video w-40 shrink-0 bg-muted',
                onFocus &&
                    'cursor-pointer transition-colors hover:border-primary/60 focus-visible:ring-2 focus-visible:outline-none',
                focused && 'border-primary',
            )}
        >
            {stream ? (
                <video
                    ref={video}
                    autoPlay
                    playsInline
                    /*
                        Your own picture never plays its own sound: the audio is
                        already going out over the connection, and playing it
                        back here is the echo everybody recognises from a badly
                        set up call.
                    */
                    muted={own}
                    className={cn(
                        size === 'stage'
                            ? /*
                               * h-full rather than max-h-full: a max only ever
                               * scales down, so a 640x480 webcam stayed 640x480
                               * in the middle of a thousand-pixel stage. This
                               * scales up to fit and object-contain keeps the
                               * shape while it does.
                               */
                              'h-full w-full object-contain'
                            : 'size-full object-cover',
                        // Mirrored, but only your own: a mirror is how you are
                        // used to seeing yourself, and how you expect a wave to
                        // move. Somebody else mirrored is somebody with their
                        // text back to front.
                        own && '-scale-x-100',
                    )}
                />
            ) : (
                <div
                    className={cn(
                        'flex size-full items-center justify-center font-medium text-muted-foreground',
                        size === 'stage' ? 'text-5xl' : 'text-sm',
                    )}
                >
                    {getInitials(name ?? '?')}
                </div>
            )}

            <span className="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/60 to-transparent px-2 py-1 text-[11px] text-white">
                {name}
            </span>
        </div>
    );
}
