import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One row of a list: a marker in a column of its own, then the text.
 *
 * Drawn rather than left to `list-style`, and the reason is not taste. A native
 * marker lives in a box outside the content, sized by the browser from the
 * padding — and with a monospace face and a number in it, that box came up
 * short and the digits were clipped down their left edge. Widening the padding
 * would have been guessing at the same measurement from the other side.
 *
 * A marker of our own is a fixed column, so it is always fully visible, and all
 * three list types can share this row — which is what keeps the text of a
 * bullet, a number and a to-do starting at the same place when they follow one
 * another in a document.
 *
 * contentEditable={false} on the marker: without it Slate treats it as part of
 * the text, and the caret can land in the bullet.
 */
export function DocumentListRow({
    marker,
    children,
    className,
}: {
    marker: ReactNode;
    children: ReactNode;
    /** Applied to the text, for the strike-through on a finished to-do. */
    className?: string;
}) {
    return (
        <li className="flex items-start gap-2">
            <span
                contentEditable={false}
                /*
                 * Exactly one line tall with its contents centred, so the
                 * marker sits on the first line of the text whatever the font
                 * does — and stays there when a long item wraps. The height
                 * reads .document-prose's own line-height rather than repeating
                 * the number.
                 */
                className="flex h-[calc(var(--document-line-height)*1em)] w-[1.5em] shrink-0 items-center justify-center text-muted-foreground tabular-nums"
            >
                {marker}
            </span>

            {/* min-w-0 so a long item wraps inside the row rather than pushing
                the marker off the left edge. */}
            <span className={cn('min-w-0 flex-1', className)}>{children}</span>
        </li>
    );
}
