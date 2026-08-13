import { X } from 'lucide-react';
import { useRef } from 'react';

import { useTranslate } from '@/hooks/use-translate';
import { applyGesture, toPixels } from '@/lib/contract-fields';
import type {
    FieldBox,
    GestureMode,
    RenderedPage,
    ResizeHandle,
} from '@/lib/contract-fields';
import { cn } from '@/lib/utils';

export interface FieldDraft extends FieldBox {
    /** Null for a box that has not been saved yet. */
    id: number | null;
    page: number;
    type: string;
    label: string;
    isRequired: boolean;
    /** Null means the first signer — see ContractField::signerIndex. */
    signerIndex: number | null;
}

/**
 * One box over the page, draggable and resizable.
 *
 * Pointer events rather than mouse events throughout, and that is not
 * future-proofing: the same editor is opened on a tablet, and a mouse-only
 * implementation is one that silently does nothing there. setPointerCapture is
 * what keeps a drag alive when the pointer leaves the box, which happens on
 * roughly every drag that is not very slow.
 *
 * The box knows nothing about pages or zoom. It is handed the size the page is
 * rendered at right now and gives back fractions — see lib/contract-fields.
 */
export function ContractFieldBox({
    draft,
    page,
    selected,
    disabled,
    onSelect,
    onChange,
    onRemove,
}: {
    draft: FieldDraft;
    page: RenderedPage;
    selected: boolean;
    disabled: boolean;
    onSelect: () => void;
    onChange: (box: FieldBox) => void;
    onRemove: () => void;
}) {
    const { t } = useTranslate();

    /*
     * The gesture in flight: where the pointer started, the box as it stood
     * then, and which of the five things is being dragged.
     *
     * A ref rather than state, because it changes on every pointermove and
     * re-rendering for it would drop frames on a long drag. The box in here is
     * the source of truth for the gesture: reading it back off the props would
     * lose the movements React has not committed yet, which shows up as a box
     * that lags behind the pointer and then snaps.
     */
    const gesture = useRef<{
        x: number;
        y: number;
        box: FieldBox;
        mode: GestureMode;
    } | null>(null);

    /*
     * Which corner was pressed, waiting for the box's own handler to pick it up.
     *
     * This exists because the handles sit *inside* the box, so a press on one
     * reaches the handle first and the box a moment later, on the way up. The
     * handle writes down which corner it was; the box reads it and starts the
     * gesture.
     *
     * The alternative — a second pointermove handler on the handle — is what
     * this replaced, and it silently did not work: both handlers fired for
     * every move, the corner's resize ran first, and the box's move overwrote
     * it. Every attempt to resize simply moved the box instead. One handler
     * that knows what it is dragging cannot fail that way.
     */
    const pressed = useRef<ResizeHandle | null>(null);

    const pixels = toPixels(draft, page);

    const begin = (event: React.PointerEvent) => {
        // Stop the click reaching the page underneath, which would put a new
        // box down on top of the one being dragged.
        event.stopPropagation();

        if (disabled) {
            return;
        }

        onSelect();

        /*
         * Captured on the box rather than on the corner, so every move for the
         * rest of this gesture is delivered here — including the ones that
         * happen after the pointer has left a handle two millimetres wide,
         * which is most of them.
         */
        event.currentTarget.setPointerCapture(event.pointerId);

        gesture.current = {
            x: event.clientX,
            y: event.clientY,
            box: draft,
            mode: pressed.current ?? 'move',
        };

        pressed.current = null;
    };

    const drag = (event: React.PointerEvent) => {
        if (gesture.current === null) {
            return;
        }

        const { x, y, box, mode } = gesture.current;

        const deltaX = event.clientX - x;
        const deltaY = event.clientY - y;

        /*
         * The gesture keeps its original anchor and the *whole* movement is
         * applied to it each time, rather than the box being nudged by the
         * step since the last event. Nudging accumulates the clamping: drag
         * into the edge of the page and back out, and a step-based box would
         * have lost everything it was clamped by on the way in.
         */
        onChange(applyGesture(box, mode, deltaX, deltaY, page));
    };

    const end = (event: React.PointerEvent) => {
        gesture.current = null;
        pressed.current = null;

        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    };

    return (
        <div
            role="button"
            tabIndex={disabled ? -1 : 0}
            aria-label={draft.label}
            data-testid="contract-field-box"
            data-field-type={draft.type}
            onPointerDown={begin}
            onPointerMove={drag}
            onPointerUp={end}
            onPointerCancel={end}
            className={cn(
                /*
                 * touch-none is what makes any of this work on a tablet:
                 * without it the browser reads the first movement as a scroll
                 * and takes the pointer away mid-drag.
                 */
                'absolute touch-none rounded-sm border-2 text-[10px] leading-tight select-none',
                disabled ? 'cursor-default' : 'cursor-move',
                selected
                    ? 'border-primary bg-primary/15'
                    : 'border-primary/50 bg-primary/5 hover:bg-primary/10',
            )}
            style={{
                left: pixels.left,
                top: pixels.top,
                width: pixels.width,
                height: pixels.height,
            }}
        >
            {/*
                The label is clipped rather than wrapped, and pointer-events are
                off it: a box may be two millimetres tall — a tickbox — and text
                that could be grabbed would make the box itself hard to catch.
            */}
            <span className="pointer-events-none absolute inset-0 overflow-hidden px-1 py-0.5 text-primary">
                {draft.label}
                {draft.isRequired && <span aria-hidden="true">{' *'}</span>}
            </span>

            {selected && !disabled && (
                <>
                    {(['nw', 'ne', 'sw', 'se'] as ResizeHandle[]).map(
                        (handle) => (
                            <span
                                key={handle}
                                data-testid={`contract-field-handle-${handle}`}
                                /*
                                    Only says which corner it is. The press then
                                    carries on up to the box, which starts the
                                    gesture and handles every move — see the
                                    note on `pressed` above for why this is not
                                    a second drag handler.
                                */
                                onPointerDown={() => {
                                    pressed.current = handle;
                                }}
                                className={cn(
                                    /*
                                        A dot of ten pixels with a hit area of
                                        twenty-four around it. The dot is what
                                        you aim at; the padding is what a finger
                                        actually lands on, and without it these
                                        are unusable on the tablets people lay
                                        contracts out on.
                                    */
                                    'absolute size-2.5 rounded-full border border-background bg-primary',
                                    'before:absolute before:-inset-2 before:content-[""]',
                                    handle === 'nw' &&
                                        '-top-1.5 -left-1.5 cursor-nwse-resize',
                                    handle === 'ne' &&
                                        '-top-1.5 -right-1.5 cursor-nesw-resize',
                                    handle === 'sw' &&
                                        '-bottom-1.5 -left-1.5 cursor-nesw-resize',
                                    handle === 'se' &&
                                        '-right-1.5 -bottom-1.5 cursor-nwse-resize',
                                )}
                            />
                        ),
                    )}

                    <button
                        type="button"
                        aria-label={t('contracts.editor.remove_field')}
                        onPointerDown={(event) => event.stopPropagation()}
                        onClick={(event) => {
                            event.stopPropagation();
                            onRemove();
                        }}
                        className="absolute -top-2.5 -right-7 rounded-full border bg-background p-0.5 text-muted-foreground shadow-sm hover:text-destructive"
                    >
                        <X className="size-3" />
                    </button>
                </>
            )}
        </div>
    );
}
