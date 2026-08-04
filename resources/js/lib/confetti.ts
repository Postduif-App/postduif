import { isEmojiOnly } from '@/lib/emoji-only';

/**
 * The three ways people say "we did it" without words: the party popper, the
 * confetti ball, and the face wearing the hat.
 *
 * Kept to these three on purpose. Every emoji added here is one more message
 * that takes over somebody's whole screen, and 🥂 or ✨ or 🔥 are said far more
 * often in passing than in celebration.
 */
const PARTY = ['\u{1F389}', '\u{1F38A}', '\u{1F973}'];

/**
 * A message that is nothing but celebration, in any mix of those three.
 *
 * Deliberately built on top of `isEmojiOnly` rather than beside it: the
 * messages that celebrate are exactly the messages that already get drawn
 * large, so the two effects can never disagree about what counts as "just an
 * emoji". It also inherits that rule's grapheme counting and its refusal to
 * decide anything where Intl.Segmenter is missing.
 */
export function isCelebration(body: string): boolean {
    if (!isEmojiOnly(body)) {
        return false;
    }

    // The variation selector says "draw this as a picture" and is invisible
    // either way — somebody whose keyboard adds one is still just cheering.
    const trimmed = body.trim().replaceAll('\u{FE0F}', '');

    // Splitting by code point is safe here where it would not be in general:
    // none of the three takes a skin tone or joins into a longer sequence, so
    // every character has to be one of them on its own.
    return (
        trimmed !== '' &&
        [...trimmed].every((character) => PARTY.includes(character))
    );
}

/** Enough to read as a shower rather than as a handful of falling squares. */
const PIECE_COUNT = 140;

/** After this the party is over, however slowly the last scraps fall. */
const DURATION_MS = 4000;

/**
 * Picked to stay legible on both a white and a near-black background, which is
 * why there is no yellow and no navy in the list.
 */
const COLORS = [
    '#f43f5e',
    '#f59e0b',
    '#22c55e',
    '#3b82f6',
    '#a855f7',
    '#ec4899',
];

const GRAVITY = 0.12;
const DRAG = 0.995;

interface Piece {
    x: number;
    y: number;
    vx: number;
    vy: number;
    size: number;
    /** Where in its own spin the scrap is, which is what makes it flutter. */
    tilt: number;
    spin: number;
    color: string;
}

/**
 * One party at a time. Two 🎉 messages scrolling into view together — or React
 * mounting an effect twice in development — would otherwise stack canvases on
 * top of each other and run the shower at double density.
 */
let running = false;

/**
 * Confetti across the whole window, once.
 *
 * Drawn on a canvas appended straight to the body rather than inside the React
 * tree: it covers the entire viewport, sits above every dialog, and is over in
 * four seconds. Nothing about it belongs to the message row that set it off, and
 * routing it through state would only make every ancestor re-render for it.
 */
export function fireConfetti(): void {
    if (running || typeof document === 'undefined') {
        return;
    }

    // Someone who has asked their system for less motion has asked for exactly
    // this: a screenful of moving scraps they did not request.
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context) {
        return;
    }

    running = true;

    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText =
        'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9999';

    const width = window.innerWidth;
    const height = window.innerHeight;
    const ratio = window.devicePixelRatio || 1;

    canvas.width = width * ratio;
    canvas.height = height * ratio;
    context.scale(ratio, ratio);

    document.body.append(canvas);

    /*
        Everything starts above the fold, spread over a band rather than a line,
        so the shower arrives over about a second instead of as one curtain
        dropping in unison.
    */
    const pieces: Piece[] = Array.from({ length: PIECE_COUNT }, () => ({
        x: Math.random() * width,
        y: -Math.random() * height * 0.8 - 20,
        vx: (Math.random() - 0.5) * 2.5,
        vy: 2 + Math.random() * 3,
        size: 6 + Math.random() * 6,
        tilt: Math.random() * Math.PI * 2,
        spin: (Math.random() - 0.5) * 0.25,
        color: COLORS[Math.floor(Math.random() * COLORS.length)],
    }));

    const started = performance.now();
    let frame = 0;

    const stop = () => {
        cancelAnimationFrame(frame);
        canvas.remove();
        running = false;
    };

    const draw = (now: number) => {
        const elapsed = now - started;

        context.clearRect(0, 0, width, height);

        // The last half second fades out instead of cutting off, which would
        // otherwise make the confetti vanish mid-air.
        context.globalAlpha = Math.min(
            1,
            Math.max(0, (DURATION_MS - elapsed) / 500),
        );

        let visible = false;

        for (const piece of pieces) {
            piece.vy = piece.vy * DRAG + GRAVITY;
            piece.vx *= DRAG;
            piece.x += piece.vx;
            piece.y += piece.vy;
            piece.tilt += piece.spin;

            if (piece.y < height + piece.size) {
                visible = true;
            }

            context.save();
            context.translate(piece.x, piece.y);
            context.rotate(piece.tilt);
            // Squashing the height by the spin turns a rectangle into a scrap
            // of paper turning over as it falls — the cheapest way to suggest a
            // third dimension on a flat canvas.
            context.fillStyle = piece.color;
            context.fillRect(
                -piece.size / 2,
                -piece.size / 4,
                piece.size,
                (piece.size / 2) * Math.abs(Math.cos(piece.tilt)),
            );
            context.restore();
        }

        if (elapsed > DURATION_MS || !visible) {
            stop();

            return;
        }

        frame = requestAnimationFrame(draw);
    };

    frame = requestAnimationFrame(draw);
}
