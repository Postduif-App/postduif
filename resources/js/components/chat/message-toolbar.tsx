import type { PropsWithChildren } from 'react';

import { cn } from '@/lib/utils';

/**
 * The actions on a message, as one bar rather than a row of loose buttons.
 *
 * The container owns the border, the rounding and the shadow; the buttons
 * inside are flat and separated by hairlines. That way the group reads as a
 * single object hovering over the message — four bordered boxes side by side
 * read as four unrelated controls that happen to sit near each other.
 *
 * It stays put while one of its dropdowns is open (has-[[data-state=open]]) —
 * otherwise moving the mouse from the row to the emoji list would pull the
 * trigger out from under it. Fading rather than hiding keeps the row from
 * reflowing and keeps the buttons reachable by keyboard.
 *
 * Pulled up over the message's own top edge, the way it sits in every chat
 * client: the bar then overlaps the whitespace above rather than covering the
 * first line of what you are reading.
 */
export function MessageToolbar({
    open = false,
    children,
}: PropsWithChildren<{
    /**
     * Shown regardless of the pointer, because the message was picked.
     *
     * This is what a device that cannot hover uses instead: tapping a message
     * asks for its actions. It used to stand open permanently there — reacting
     * and replying have to exist on a phone — but a bar that is always up
     * covers the line above it on every message on the screen at once.
     */
    open?: boolean;
}>) {
    return (
        /*
         * pointer-events follow the opacity rather than staying on.
         *
         * An invisible bar that still takes clicks is worse on a touchscreen
         * than a visible one: the top right corner of every message would
         * quietly swallow the tap meant for the words underneath it.
         */
        <div
            className={cn(
                'absolute -top-2.5 right-2 flex items-center divide-x overflow-hidden rounded-md border bg-background opacity-0 shadow-sm transition-opacity group-hover:pointer-events-auto group-hover:opacity-100 focus-within:pointer-events-auto focus-within:opacity-100 has-[[data-state=open]]:pointer-events-auto has-[[data-state=open]]:opacity-100',
                /*
                 * Picked or hovered, the bar is in exactly the same place and
                 * takes no room of its own: it fades in over the whitespace
                 * above the message, and the conversation underneath does not
                 * move a pixel. Anything that opened a gap for it would shift
                 * every message below the one you touched.
                 */
                open
                    ? 'pointer-events-auto opacity-100'
                    : 'pointer-events-none',
            )}
        >
            {children}
        </div>
    );
}

/**
 * The look of one button in that bar. A class helper rather than a wrapper
 * component: the reaction picker's button is a dropdown trigger and cannot be
 * wrapped in anything without losing what makes it one.
 */
export function messageToolbarButton(className?: string): string {
    return cn(
        'flex size-7 items-center justify-center text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset',
        className,
    );
}
