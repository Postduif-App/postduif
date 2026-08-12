/**
 * The drawing arithmetic behind a signature pad.
 *
 * Pure, and here rather than inside the component, so it can be tested without
 * a browser. That matters for the same reason the field geometry does: what
 * these functions produce ends up on a document somebody signed, and a bug in
 * them is a bug in an image nobody can correct afterwards.
 *
 * A stroke is a list of points in canvas pixels. Nothing here knows about
 * pointer events, canvases or React — it is given points and asked questions
 * about them.
 */

export interface Point {
    x: number;
    y: number;
}

export type Stroke = Point[];

/**
 * Whether anything has actually been drawn.
 *
 * A single point is a tap rather than a signature, and a pad that accepted one
 * would let somebody "sign" by brushing the screen while scrolling. The
 * question is asked of the whole drawing rather than per stroke, so a dot on
 * the i of a signature made of several strokes still counts.
 */
export function hasInk(strokes: readonly Stroke[]): boolean {
    return strokes.some((stroke) => stroke.length > 1);
}

/**
 * The box the drawing actually occupies, in canvas pixels.
 *
 * Used to crop before uploading. Somebody signing on a wide pad usually uses a
 * third of it, and storing the whole canvas would paste a signature into the
 * contract at a third of its intended size with empty space around it — which
 * reads as a signature that does not fit its line.
 *
 * Null when there is nothing to measure.
 */
export function inkBounds(strokes: readonly Stroke[]): {
    left: number;
    top: number;
    right: number;
    bottom: number;
} | null {
    let left = Infinity;
    let top = Infinity;
    let right = -Infinity;
    let bottom = -Infinity;

    for (const stroke of strokes) {
        for (const point of stroke) {
            left = Math.min(left, point.x);
            top = Math.min(top, point.y);
            right = Math.max(right, point.x);
            bottom = Math.max(bottom, point.y);
        }
    }

    if (left === Infinity) {
        return null;
    }

    return { left, top, right, bottom };
}

/**
 * The same box with room around it.
 *
 * A signature cropped exactly to its ink touches all four edges, and the line
 * width means half a stroke would be cut off. The padding is what keeps the
 * descender of a "g" from being shaved.
 *
 * Clamped to the canvas, because a signature drawn against the left edge cannot
 * be given padding that is not there.
 */
export function paddedBounds(
    bounds: { left: number; top: number; right: number; bottom: number },
    padding: number,
    canvas: { width: number; height: number },
): { left: number; top: number; width: number; height: number } {
    const left = Math.max(0, bounds.left - padding);
    const top = Math.max(0, bounds.top - padding);
    const right = Math.min(canvas.width, bounds.right + padding);
    const bottom = Math.min(canvas.height, bounds.bottom + padding);

    return {
        left,
        top,
        // Never zero: a signature that is one perfectly straight horizontal
        // line — somebody signing with a flourish — has no height of its own,
        // and a canvas of zero height cannot be created at all.
        width: Math.max(1, right - left),
        height: Math.max(1, bottom - top),
    };
}

/**
 * A point smoothed against the one before it.
 *
 * Pointer events arrive at whatever rate the device manages, which on a cheap
 * phone is far below the rate a hand moves — so a stroke drawn straight from
 * the raw points is a row of visible corners. Taking the midpoint of each pair
 * and curving through it is the standard trick, and it costs nothing.
 *
 * Returned as the control point and the end point of a quadratic curve, which
 * is exactly what canvas quadraticCurveTo wants.
 */
export function smoothed(
    previous: Point,
    current: Point,
): { control: Point; end: Point } {
    return {
        control: previous,
        end: {
            x: (previous.x + current.x) / 2,
            y: (previous.y + current.y) / 2,
        },
    };
}
