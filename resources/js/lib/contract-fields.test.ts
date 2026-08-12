import { describe, expect, it } from 'vitest';

import {
    MIN_SIZE,
    moveBox,
    placeBox,
    pointerFraction,
    resizeBox,
    roundBox,
    toPixels,
} from '@/lib/contract-fields';
import type { FieldBox } from '@/lib/contract-fields';

const box = (over: Partial<FieldBox> = {}): FieldBox => ({
    x: 0.2,
    y: 0.3,
    width: 0.25,
    height: 0.1,
    ...over,
});

/** A4 at a plausible screen size, and the same page zoomed to twice that. */
const page = { width: 600, height: 848 };
const zoomed = { width: 1200, height: 1696 };

describe('toPixels', () => {
    it('turns fractions into somewhere to put a div', () => {
        const pixels = toPixels(box(), page);

        // Compared loosely on purpose: 0.3 * 848 is 254.39999999999998 in
        // binary floating point, and a strict comparison here would be a test
        // about IEEE 754 rather than about where the box goes.
        expect(pixels.left).toBeCloseTo(120, 8);
        expect(pixels.top).toBeCloseTo(254.4, 8);
        expect(pixels.width).toBeCloseTo(150, 8);
        expect(pixels.height).toBeCloseTo(84.8, 8);
    });
});

describe('moveBox', () => {
    it('moves a box by the fraction of the page the pointer covered', () => {
        const moved = moveBox(box(), 60, 0, page);

        // A tenth of the page's width to the right.
        expect(moved.x).toBeCloseTo(0.3, 10);
        expect(moved.y).toBe(0.3);
    });

    /*
     * The test the epic calls the one that really matters.
     *
     * Zoom changes how many pixels a gesture covers and changes nothing about
     * what the person saw. Dragging a box a quarter of the way across the page
     * has to store the same four numbers whether the page is 600 or 1200 wide —
     * otherwise a contract laid out on a laptop has its signature boxes
     * somewhere else on a monitor, and nobody would notice until it was signed.
     */
    it('stores the same box whatever the page is rendered at', () => {
        const atNormalZoom = moveBox(box(), 150, 212, page);
        const atDoubleZoom = moveBox(box(), 300, 424, zoomed);

        expect(atDoubleZoom.x).toBeCloseTo(atNormalZoom.x, 10);
        expect(atDoubleZoom.y).toBeCloseTo(atNormalZoom.y, 10);
        expect(atDoubleZoom.width).toBe(atNormalZoom.width);
        expect(atDoubleZoom.height).toBe(atNormalZoom.height);
    });

    it('keeps the whole box on the page', () => {
        // Far past the right and bottom edges, in one shove.
        const shoved = moveBox(box(), 10_000, 10_000, page);

        expect(shoved.x).toBeCloseTo(1 - shoved.width, 10);
        expect(shoved.y).toBeCloseTo(1 - shoved.height, 10);
    });

    it('will not push a box off the top or the left either', () => {
        const shoved = moveBox(box(), -10_000, -10_000, page);

        expect(shoved.x).toBe(0);
        expect(shoved.y).toBe(0);
    });

    it('does nothing while the page has no size yet', () => {
        // The moment between mounting and pdf.js having measured anything.
        expect(moveBox(box(), 40, 40, { width: 0, height: 0 })).toEqual(box());
    });
});

describe('resizeBox', () => {
    it('moves the corner being dragged and leaves the opposite one', () => {
        const resized = resizeBox(box(), 'se', 60, 84.8, page);

        // The top-left stayed put; the box grew by a tenth in both directions.
        expect(resized.x).toBe(0.2);
        expect(resized.y).toBe(0.3);
        expect(resized.width).toBeCloseTo(0.35, 10);
        expect(resized.height).toBeCloseTo(0.2, 10);
    });

    it('anchors the far corner when the near one is dragged', () => {
        const resized = resizeBox(box(), 'nw', 60, 0, page);

        // The right edge is where it was: 0.2 + 0.25.
        expect(resized.x + resized.width).toBeCloseTo(0.45, 10);
        expect(resized.x).toBeCloseTo(0.3, 10);
    });

    it('gives the same result at any zoom', () => {
        const atNormalZoom = resizeBox(box(), 'se', 90, 127.2, page);
        const atDoubleZoom = resizeBox(box(), 'se', 180, 254.4, zoomed);

        expect(atDoubleZoom.width).toBeCloseTo(atNormalZoom.width, 10);
        expect(atDoubleZoom.height).toBeCloseTo(atNormalZoom.height, 10);
    });

    it('turns a box dragged inside out into a small one, not a negative one', () => {
        // The right edge dragged well past the left.
        const resized = resizeBox(box(), 'se', -600, -848, page);

        expect(resized.width).toBeGreaterThanOrEqual(MIN_SIZE);
        expect(resized.height).toBeGreaterThanOrEqual(MIN_SIZE);
    });

    it('keeps the minimum against the corner that was held', () => {
        /*
         * The north-west corner dragged exactly onto the south-east one, which
         * is a box of no size. It has to come back as the minimum measured
         * *from the anchored corner* — grown up and to the left — because a box
         * that grew the other way would jump out from under the pointer.
         *
         * 150 and 84.8 pixels are this box's own width and height on this page.
         */
        const resized = resizeBox(box(), 'nw', 150, 84.8, page);

        expect(resized.width).toBeCloseTo(MIN_SIZE, 8);
        expect(resized.height).toBeCloseTo(MIN_SIZE, 8);
        expect(resized.x + resized.width).toBeCloseTo(0.45, 8);
        expect(resized.y + resized.height).toBeCloseTo(0.4, 8);
    });

    it('flips the box when a corner is dragged past the anchored one', () => {
        /*
         * Not a special case, and worth a test rather than a comment: dragging
         * the north-west corner well past the south-east one turns the box
         * inside out and it carries on growing the other way, the way every
         * drawing tool behaves. What must never happen is a negative width, and
         * that is what sorting the edges buys.
         */
        const resized = resizeBox(box(), 'nw', 600, 0, page);

        expect(resized.width).toBeGreaterThan(0);
        // The old right edge is now the left one.
        expect(resized.x).toBeCloseTo(0.45, 8);
    });

    it('will not let a corner leave the page', () => {
        const resized = resizeBox(box(), 'se', 10_000, 10_000, page);

        expect(resized.x + resized.width).toBeLessThanOrEqual(1);
        expect(resized.y + resized.height).toBeLessThanOrEqual(1);
    });
});

describe('placeBox', () => {
    it('puts a new box around the click rather than beside it', () => {
        const placed = placeBox(0.5, 0.5, { width: 0.26, height: 0.08 });

        expect(placed.x + placed.width / 2).toBeCloseTo(0.5, 10);
        expect(placed.y + placed.height / 2).toBeCloseTo(0.5, 10);
    });

    it('nudges a box clicked at the foot of the page back on', () => {
        // Where a signature is asked for, nine times out of ten.
        const placed = placeBox(0.5, 0.99, { width: 0.26, height: 0.08 });

        expect(placed.y + placed.height).toBeLessThanOrEqual(1);
        expect(placed.y).toBeGreaterThanOrEqual(0);
    });
});

describe('pointerFraction', () => {
    it('reads a click as a fraction of the page it landed on', () => {
        const at = pointerFraction(400, 500, {
            left: 100,
            top: 100,
            width: 600,
            height: 800,
        });

        expect(at.x).toBeCloseTo(0.5, 10);
        expect(at.y).toBeCloseTo(0.5, 10);
    });

    it('keeps a click just outside the page inside it', () => {
        const at = pointerFraction(-50, 5000, {
            left: 100,
            top: 100,
            width: 600,
            height: 800,
        });

        expect(at.x).toBe(0);
        expect(at.y).toBe(1);
    });
});

describe('roundBox', () => {
    it('drops the float noise a chain of drags leaves behind', () => {
        const tidied = roundBox({
            x: 0.1 + 0.2,
            y: 0.30000000000000004,
            width: 0.25,
            height: 0.1,
        });

        expect(tidied.x).toBe(0.3);
        expect(tidied.y).toBe(0.3);
    });

    it('keeps everything the column can hold', () => {
        const tidied = roundBox({
            x: 0.12345678,
            y: 0.87654321,
            width: 0.25,
            height: 0.1,
        });

        expect(tidied.x).toBe(0.12345678);
        expect(tidied.y).toBe(0.87654321);
    });
});
