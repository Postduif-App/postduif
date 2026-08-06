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
export function MessageToolbar({ children }: PropsWithChildren) {
    return (
        /*
         * Always there on a device that cannot hover.
         *
         * Hiding it until the pointer arrives is right with a mouse and quietly
         * disastrous on a phone: there is no hover, so reacting, replying,
         * saving and deleting a message simply do not exist. The media query
         * asks about the input rather than the width, which is the actual
         * question — a touchscreen laptop is narrow about neither.
         */
        <div className="absolute -top-2.5 right-2 flex items-center divide-x overflow-hidden rounded-md border bg-background opacity-0 shadow-sm transition-opacity group-hover:opacity-100 focus-within:opacity-100 has-[[data-state=open]]:opacity-100 [@media(hover:none)]:opacity-100">
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
