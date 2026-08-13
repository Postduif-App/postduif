/**
 * The geometry of a box drawn over a page of a contract.
 *
 * Pure, and here rather than beside the editor that uses it, so it can be
 * tested without a browser and without pulling in the pdf.js chunk. That
 * matters more here than for most helpers: every rule in this file is a rule
 * about where a signature ends up on a legal document, and "we hebben het even
 * bekeken in Chrome" is not the standard those deserve.
 *
 * The one idea running through all of it: a stored box is four fractions of the
 * page, never pixels. 0 is the left or top edge, 1 the right or bottom. The
 * page is rendered at whatever size the screen allows — a laptop, a phone, a
 * zoom level somebody dragged — and pixels only ever exist between reading a
 * pointer event and writing the fraction back. See the migration for the
 * database side of the same decision.
 */

/** What a page looks like on screen right now, in CSS pixels. */
export interface RenderedPage {
    width: number;
    height: number;
}

/** A box, as it is stored: four fractions of the page. */
export interface FieldBox {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** The same box in pixels, for putting a div where somebody can grab it. */
export interface PixelBox {
    left: number;
    top: number;
    width: number;
    height: number;
}

/**
 * The smallest a box may be dragged to, as a fraction of the page.
 *
 * Not zero, and not a pixel count. A box of no size is one that cannot be
 * grabbed again to fix it — the handle sits on top of the handle — so the
 * editor would silently produce fields nobody can edit or click. A hundredth of
 * the page is about two millimetres on A4: small enough for a tickbox, large
 * enough to catch.
 */
export const MIN_SIZE = 0.01;

/** Keep a number inside its bounds. */
export function clamp(value: number, low: number, high: number): number {
    return Math.min(high, Math.max(low, value));
}

/**
 * Where to draw a stored box, given how big the page is right now.
 *
 * The only place fractions become pixels. Everything downstream of this is
 * presentation; everything upstream is the document.
 */
export function toPixels(box: FieldBox, page: RenderedPage): PixelBox {
    return {
        left: box.x * page.width,
        top: box.y * page.height,
        width: box.width * page.width,
        height: box.height * page.height,
    };
}

/**
 * A pointer movement, in fractions of the page.
 *
 * Divided by the size the page is rendered at *now*, which is the whole reason
 * zoom cannot corrupt a box: dragging fifty pixels across a page rendered at
 * 500 wide moves it a tenth of the page, and dragging fifty across the same
 * page at 1000 wide moves it a twentieth. Both are the same gesture on screen
 * and both store what the eye saw.
 *
 * A page of zero size is the moment between mounting and pdf.js having
 * measured anything. Dividing by it would produce Infinity and put the box in a
 * corner, so a movement across a page that is not there yet is no movement.
 */
export function toFraction(
    deltaX: number,
    deltaY: number,
    page: RenderedPage,
): { x: number; y: number } {
    return {
        x: page.width > 0 ? deltaX / page.width : 0,
        y: page.height > 0 ? deltaY / page.height : 0,
    };
}

/**
 * Move a box by a pointer movement, keeping it on the page.
 *
 * Clamped so the box stays whole rather than being cut off at the edge: a
 * signature half over the margin is not something anybody meant to draw, and
 * the renderer would have to decide what to do with the half that hangs off.
 */
export function moveBox(
    box: FieldBox,
    deltaX: number,
    deltaY: number,
    page: RenderedPage,
): FieldBox {
    const delta = toFraction(deltaX, deltaY, page);

    return {
        ...box,
        x: clamp(box.x + delta.x, 0, 1 - box.width),
        y: clamp(box.y + delta.y, 0, 1 - box.height),
    };
}

/** Which corner or edge is being dragged. */
export type ResizeHandle = 'nw' | 'ne' | 'sw' | 'se';

/**
 * Resize a box from one of its corners.
 *
 * The corner being dragged moves and the opposite one stays put, which is what
 * anybody who has resized anything expects. Written as "work out both edges,
 * then sort them" rather than as four cases, because dragging the right edge
 * past the left is an ordinary thing to do with a mouse and the result should
 * be a small box rather than a negative one.
 *
 * The minimum is applied by pushing the dragged edge back, never by moving the
 * anchored one: a box that jumped away from the corner somebody was holding
 * would feel like the editor fighting them.
 */
export function resizeBox(
    box: FieldBox,
    handle: ResizeHandle,
    deltaX: number,
    deltaY: number,
    page: RenderedPage,
): FieldBox {
    const delta = toFraction(deltaX, deltaY, page);

    const movesLeft = handle === 'nw' || handle === 'sw';
    const movesTop = handle === 'nw' || handle === 'ne';

    // The edge that stays where it is, and the one under the pointer.
    const anchorX = movesLeft ? box.x + box.width : box.x;
    const anchorY = movesTop ? box.y + box.height : box.y;

    const draggedX = clamp(
        (movesLeft ? box.x : box.x + box.width) + delta.x,
        0,
        1,
    );
    const draggedY = clamp(
        (movesTop ? box.y : box.y + box.height) + delta.y,
        0,
        1,
    );

    const left = Math.min(anchorX, draggedX);
    const right = Math.max(anchorX, draggedX);
    const top = Math.min(anchorY, draggedY);
    const bottom = Math.max(anchorY, draggedY);

    return {
        ...box,
        ...withMinimumSize(left, right, top, bottom, anchorX, anchorY),
    };
}

/**
 * Give a collapsed box its minimum back, from the anchored side.
 *
 * Split out because it is the fiddly half: which way to push depends on which
 * edge was anchored, and getting it wrong moves the corner the person is
 * holding.
 */
function withMinimumSize(
    left: number,
    right: number,
    top: number,
    bottom: number,
    anchorX: number,
    anchorY: number,
): FieldBox {
    let x = left;
    let width = right - left;
    let y = top;
    let height = bottom - top;

    if (width < MIN_SIZE) {
        width = MIN_SIZE;
        // The anchor was the right edge, so grow leftwards from it — unless
        // that would run off the page, in which case grow the other way.
        x = anchorX === right ? Math.max(0, anchorX - MIN_SIZE) : left;
        x = Math.min(x, 1 - MIN_SIZE);
    }

    if (height < MIN_SIZE) {
        height = MIN_SIZE;
        y = anchorY === bottom ? Math.max(0, anchorY - MIN_SIZE) : top;
        y = Math.min(y, 1 - MIN_SIZE);
    }

    return { x, y, width, height };
}

/**
 * What a gesture is doing to a box.
 *
 * 'move' for a drag anywhere on the box itself; a corner for a drag that
 * started on one of its handles.
 */
export type GestureMode = 'move' | ResizeHandle;

/**
 * Apply a pointer movement to a box, according to what is being dragged.
 *
 * One function rather than the caller choosing between moveBox and resizeBox,
 * and it is here rather than in the component because of how it went wrong the
 * first time: the corner handles sit inside the box, so both had a drag handler
 * and both ran for every movement — the resize happened and the move
 * immediately overwrote it. Every attempt to resize a field silently moved it.
 *
 * With the decision in one place there is one handler, and it cannot be half
 * applied.
 */
export function applyGesture(
    box: FieldBox,
    mode: GestureMode,
    deltaX: number,
    deltaY: number,
    page: RenderedPage,
): FieldBox {
    return mode === 'move'
        ? moveBox(box, deltaX, deltaY, page)
        : resizeBox(box, mode, deltaX, deltaY, page);
}

/**
 * Put a new box down where somebody clicked, at its type's usual size.
 *
 * The click is the centre rather than the corner, because that is where the eye
 * is: somebody pointing at the line under "Handtekening" means "hier", not
 * "hier begint hij". Nudged back onto the page when the click was near an edge,
 * which is the ordinary case for a signature at the foot of a page.
 */
export function placeBox(
    fractionX: number,
    fractionY: number,
    size: { width: number; height: number },
): FieldBox {
    const width = clamp(size.width, MIN_SIZE, 1);
    const height = clamp(size.height, MIN_SIZE, 1);

    return {
        x: clamp(fractionX - width / 2, 0, 1 - width),
        y: clamp(fractionY - height / 2, 0, 1 - height),
        width,
        height,
    };
}

/**
 * Where a pointer is on the page, as fractions.
 *
 * Takes the rectangle rather than reading it off the element, so this stays
 * pure and the caller does the one impure thing — getBoundingClientRect — at
 * the moment it actually happens.
 */
export function pointerFraction(
    clientX: number,
    clientY: number,
    rect: { left: number; top: number; width: number; height: number },
): { x: number; y: number } {
    return {
        x: rect.width > 0 ? clamp((clientX - rect.left) / rect.width, 0, 1) : 0,
        y:
            rect.height > 0
                ? clamp((clientY - rect.top) / rect.height, 0, 1)
                : 0,
    };
}

/**
 * Round a box to something a database column and a person can both live with.
 *
 * Eight decimals is what the column holds; more than that is float noise from a
 * chain of drags, and storing it would mean two boxes that look identical
 * differing in the twelfth decimal for no reason anybody could act on.
 *
 * Applied on the way out rather than after every drag, so the arithmetic during
 * a gesture stays exact and only the result is tidied.
 */
export function roundBox(box: FieldBox): FieldBox {
    const round = (value: number) => Math.round(value * 1e8) / 1e8;

    return {
        x: round(box.x),
        y: round(box.y),
        width: round(box.width),
        height: round(box.height),
    };
}
