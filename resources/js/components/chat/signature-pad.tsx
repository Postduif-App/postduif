import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslate } from '@/hooks/use-translate';
import {
    hasInk,
    inkBounds,
    paddedBounds,
    smoothed,
} from '@/lib/signature-canvas';
import type { Point, Stroke } from '@/lib/signature-canvas';
import { cn } from '@/lib/utils';

/** Which of the two ways the mark was made. Matches the PHP enum. */
export type SignatureMethod = 'drawn' | 'typed';

/**
 * The face a typed signature is drawn in.
 *
 * Bundled with the application rather than left to the browser's idea of
 * "cursive" — see vite.config.ts. Which face it is matters here in a way it
 * does not on an ordinary page: the browser paints this into a canvas and
 * uploads the pixels, so a fallback face would not merely look different, it
 * would be what got stored on the contract.
 */
const SCRIPT_FONT = 'Caveat';

/** How thick the pen is, in canvas pixels at ratio 1. */
const PEN_WIDTH = 2.5;

/** Room left around the ink when the drawing is cropped. */
const CROP_PADDING = 8;

export interface SignaturePadProps {
    /** Prefilled into the typed field: the name this request was addressed to. */
    suggestedName: string;
    /** Handed the finished PNG and how it was made. */
    onDone: (image: Blob, method: SignatureMethod, typed?: string) => void;
    busy?: boolean;
}

/**
 * Somewhere to put a signature, either by drawing it or by typing it.
 *
 * Both roads end in the same place: a PNG with a transparent background,
 * cropped to the ink. That is deliberate — the renderer that composes the
 * signed PDF pastes an image and has no second code path, and the picture is
 * what the person actually looked at before pressing the button. Which road it
 * was is recorded separately, because the pixels cannot say.
 *
 * Pointer events throughout rather than mouse events, and that is not
 * future-proofing: most people sign this on a telephone, and a mouse-only pad
 * is one that silently does nothing there.
 */
export function SignaturePad({
    suggestedName,
    onDone,
    busy = false,
}: SignaturePadProps) {
    const { t } = useTranslate();

    const [mode, setMode] = useState<SignatureMethod>('drawn');
    const [typed, setTyped] = useState(suggestedName);

    return (
        <div className="space-y-4">
            <div className="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant={mode === 'drawn' ? 'default' : 'outline'}
                    onClick={() => setMode('drawn')}
                >
                    {t('contracts.signature.draw')}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant={mode === 'typed' ? 'default' : 'outline'}
                    onClick={() => setMode('typed')}
                >
                    {t('contracts.signature.type')}
                </Button>
            </div>

            {mode === 'drawn' ? (
                <DrawnPad busy={busy} onDone={onDone} />
            ) : (
                <TypedPad
                    value={typed}
                    onChange={setTyped}
                    busy={busy}
                    onDone={onDone}
                />
            )}

            <p className="text-xs text-muted-foreground">
                {t('contracts.signature.legal')}
            </p>
        </div>
    );
}

/**
 * The canvas somebody draws on.
 *
 * The strokes are kept as points rather than only painted, and that is what
 * makes cropping possible: once ink is on a canvas the only way to find its
 * bounds again is to read every pixel back, which is slow and needs the canvas
 * to be readable in the first place.
 */
function DrawnPad({
    busy,
    onDone,
}: {
    busy: boolean;
    onDone: SignaturePadProps['onDone'];
}) {
    const { t } = useTranslate();

    const canvas = useRef<HTMLCanvasElement | null>(null);
    const strokes = useRef<Stroke[]>([]);
    const drawing = useRef(false);
    const [inked, setInked] = useState(false);

    /*
     * The canvas is sized to its own box at the device pixel ratio, once. A
     * signature drawn at CSS resolution on a phone is a blurred line by the
     * time it is pasted into a PDF at print size.
     */
    useEffect(() => {
        const element = canvas.current;

        if (element === null) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;
        const box = element.getBoundingClientRect();

        element.width = Math.floor(box.width * ratio);
        element.height = Math.floor(box.height * ratio);

        const context = element.getContext('2d');

        if (context !== null) {
            context.scale(ratio, ratio);
            context.lineWidth = PEN_WIDTH;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#111827';
        }
    }, []);

    const pointAt = (event: React.PointerEvent<HTMLCanvasElement>): Point => {
        const box = event.currentTarget.getBoundingClientRect();

        return {
            x: event.clientX - box.left,
            y: event.clientY - box.top,
        };
    };

    const begin = (event: React.PointerEvent<HTMLCanvasElement>) => {
        // Keeps the stroke alive when the pointer leaves the pad, which happens
        // on most signatures that use the whole width.
        event.currentTarget.setPointerCapture(event.pointerId);

        drawing.current = true;
        strokes.current.push([pointAt(event)]);
    };

    const extend = (event: React.PointerEvent<HTMLCanvasElement>) => {
        if (!drawing.current) {
            return;
        }

        const stroke = strokes.current[strokes.current.length - 1];
        const previous = stroke[stroke.length - 1];
        const point = pointAt(event);

        stroke.push(point);

        const context = canvas.current?.getContext('2d');

        if (context === undefined || context === null) {
            return;
        }

        /*
         * Curved through the midpoint rather than drawn straight from sample to
         * sample. Pointer events arrive far slower than a hand moves, so
         * straight segments are a row of visible corners — see
         * lib/signature-canvas.
         */
        const { control, end } = smoothed(previous, point);

        context.beginPath();
        context.moveTo(previous.x, previous.y);
        context.quadraticCurveTo(control.x, control.y, end.x, end.y);
        context.lineTo(point.x, point.y);
        context.stroke();

        setInked(hasInk(strokes.current));
    };

    const end = (event: React.PointerEvent<HTMLCanvasElement>) => {
        drawing.current = false;

        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    };

    const clear = () => {
        const element = canvas.current;
        const context = element?.getContext('2d');

        if (element != null && context != null) {
            /*
             * clearRect rather than painting white: the background has to stay
             * transparent, because this gets pasted over a page that already
             * has a line and possibly a printed name under it.
             */
            context.clearRect(0, 0, element.width, element.height);
        }

        strokes.current = [];
        setInked(false);
    };

    const finish = () => {
        const element = canvas.current;
        const bounds = inkBounds(strokes.current);

        if (element === null || bounds === null) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;

        // The strokes were recorded in CSS pixels; the canvas holds device
        // pixels. Cropping happens in the latter.
        const box = paddedBounds(
            {
                left: bounds.left * ratio,
                top: bounds.top * ratio,
                right: bounds.right * ratio,
                bottom: bounds.bottom * ratio,
            },
            CROP_PADDING * ratio,
            { width: element.width, height: element.height },
        );

        const cropped = document.createElement('canvas');

        cropped.width = Math.round(box.width);
        cropped.height = Math.round(box.height);

        cropped
            .getContext('2d')
            ?.drawImage(
                element,
                box.left,
                box.top,
                box.width,
                box.height,
                0,
                0,
                cropped.width,
                cropped.height,
            );

        cropped.toBlob((blob) => {
            if (blob !== null) {
                onDone(blob, 'drawn');
            }
        }, 'image/png');
    };

    return (
        <div className="space-y-3">
            <canvas
                ref={canvas}
                data-testid="signature-canvas"
                onPointerDown={begin}
                onPointerMove={extend}
                onPointerUp={end}
                onPointerCancel={end}
                /*
                 * touch-none is what makes this work on a telephone at all:
                 * without it the browser reads the first movement as a scroll
                 * and takes the pointer away mid-stroke.
                 */
                className="h-40 w-full touch-none rounded-md border-2 border-dashed bg-background"
            />

            <div className="flex items-center justify-between gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={clear}
                    disabled={!inked || busy}
                >
                    {t('contracts.signature.clear')}
                </Button>

                <Button
                    type="button"
                    size="sm"
                    onClick={finish}
                    disabled={!inked || busy}
                >
                    {t('contracts.signature.use')}
                </Button>
            </div>
        </div>
    );
}

/**
 * The typed alternative, for a desktop with no touchscreen.
 *
 * Signing with a mouse produces something nobody recognises as their own
 * signature, and a simple electronic signature does not require handwriting —
 * so this is offered rather than insisted upon. What it must not do is be
 * mistaken for the drawn one, which is why the method travels with it.
 */
function TypedPad({
    value,
    onChange,
    busy,
    onDone,
}: {
    value: string;
    onChange: (value: string) => void;
    busy: boolean;
    onDone: SignaturePadProps['onDone'];
}) {
    const { t } = useTranslate();

    const [fontReady, setFontReady] = useState(false);

    /*
     * Wait for the script face before anything can be drawn.
     *
     * On an ordinary page a font that arrives late is a flash of the wrong
     * lettering. Here the browser paints the name into a canvas and uploads the
     * pixels, so a face that had not arrived would be silently stored on
     * somebody's contract in the fallback lettering — a thing nobody would
     * notice until it was signed.
     */
    useEffect(() => {
        let current = true;

        void document.fonts
            .load(`500 48px "${SCRIPT_FONT}"`)
            .then(() => {
                if (current) {
                    setFontReady(true);
                }
            })
            .catch(() => {
                // Left false: the button stays disabled rather than producing a
                // signature in a face nobody chose.
            });

        return () => {
            current = false;
        };
    }, []);

    const finish = () => {
        const name = value.trim();

        if (name === '' || !fontReady) {
            return;
        }

        const font = `500 64px "${SCRIPT_FONT}"`;

        // Measured on a scratch canvas first, so the real one is exactly as
        // wide as the name — the same crop the drawn pad does, arrived at the
        // other way round.
        const scratch = document.createElement('canvas').getContext('2d');

        if (scratch === null) {
            return;
        }

        scratch.font = font;

        const width = Math.ceil(scratch.measureText(name).width) + 16;
        const height = 100;

        const canvas = document.createElement('canvas');
        const ratio = window.devicePixelRatio || 1;

        canvas.width = Math.ceil(width * ratio);
        canvas.height = Math.ceil(height * ratio);

        const context = canvas.getContext('2d');

        if (context === null) {
            return;
        }

        context.scale(ratio, ratio);
        context.font = font;
        context.fillStyle = '#111827';
        context.textBaseline = 'middle';
        // No fillRect first: the background stays transparent, for the reason
        // the drawn pad clears rather than paints.
        context.fillText(name, 8, height / 2);

        canvas.toBlob((blob) => {
            if (blob !== null) {
                onDone(blob, 'typed', name);
            }
        }, 'image/png');
    };

    return (
        <div className="space-y-3">
            <Input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                maxLength={120}
                aria-label={t('contracts.signature.your_name')}
                placeholder={t('contracts.signature.your_name')}
            />

            <div
                aria-hidden="true"
                data-testid="signature-preview"
                className={cn(
                    'flex h-24 items-center justify-center rounded-md border-2 border-dashed bg-background text-4xl',
                    !fontReady && 'opacity-40',
                )}
                style={{ fontFamily: `"${SCRIPT_FONT}", cursive` }}
            >
                {value.trim()}
            </div>

            <div className="flex justify-end">
                <Button
                    type="button"
                    size="sm"
                    onClick={finish}
                    disabled={value.trim() === '' || !fontReady || busy}
                >
                    {t('contracts.signature.use')}
                </Button>
            </div>
        </div>
    );
}
