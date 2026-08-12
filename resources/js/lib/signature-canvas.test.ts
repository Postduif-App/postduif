import { describe, expect, it } from 'vitest';

import {
    hasInk,
    inkBounds,
    paddedBounds,
    smoothed,
} from '@/lib/signature-canvas';
import type { Stroke } from '@/lib/signature-canvas';

const stroke = (...points: [number, number][]): Stroke =>
    points.map(([x, y]) => ({ x, y }));

describe('hasInk', () => {
    it('sees nothing in an empty pad', () => {
        expect(hasInk([])).toBe(false);
    });

    it('refuses to call a single tap a signature', () => {
        /*
         * The rule that keeps somebody from "signing" by brushing the screen
         * while scrolling — which on a phone is the ordinary way to move a page.
         */
        expect(hasInk([stroke([10, 10])])).toBe(false);
    });

    it('counts a stroke that actually went somewhere', () => {
        expect(hasInk([stroke([10, 10], [20, 12])])).toBe(true);
    });

    it('counts a signature made of several strokes even with a stray dot', () => {
        // The dot on an i, drawn after the rest.
        expect(hasInk([stroke([10, 10], [40, 12]), stroke([25, 4])])).toBe(
            true,
        );
    });
});

describe('inkBounds', () => {
    it('measures across every stroke', () => {
        expect(
            inkBounds([stroke([10, 20], [30, 40]), stroke([5, 50], [60, 15])]),
        ).toEqual({ left: 5, top: 15, right: 60, bottom: 50 });
    });

    it('has nothing to measure on an empty pad', () => {
        expect(inkBounds([])).toBeNull();
    });
});

describe('paddedBounds', () => {
    const canvas = { width: 600, height: 200 };

    it('gives the drawing room on every side', () => {
        const box = paddedBounds(
            { left: 100, top: 50, right: 300, bottom: 150 },
            10,
            canvas,
        );

        expect(box).toEqual({ left: 90, top: 40, width: 220, height: 120 });
    });

    it('does not invent room the canvas does not have', () => {
        // Somebody who signed hard against the top-left corner.
        const box = paddedBounds(
            { left: 0, top: 0, right: 100, bottom: 40 },
            10,
            canvas,
        );

        expect(box.left).toBe(0);
        expect(box.top).toBe(0);
    });

    it('gives a perfectly flat signature a height anyway', () => {
        /*
         * Somebody signing with one horizontal flourish. Without this the crop
         * would be a canvas of zero height, which cannot be created at all —
         * so the upload would fail rather than the signature looking thin.
         */
        const box = paddedBounds(
            { left: 10, top: 100, right: 200, bottom: 100 },
            0,
            canvas,
        );

        expect(box.height).toBeGreaterThanOrEqual(1);
        expect(box.width).toBeGreaterThanOrEqual(1);
    });
});

describe('smoothed', () => {
    it('curves through the midpoint of the two samples', () => {
        const { control, end } = smoothed({ x: 0, y: 0 }, { x: 10, y: 20 });

        expect(control).toEqual({ x: 0, y: 0 });
        expect(end).toEqual({ x: 5, y: 10 });
    });
});
