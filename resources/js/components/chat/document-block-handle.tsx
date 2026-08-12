import { Blocks, useYooptaEditor } from '@yoopta/editor';
import { TableCommands } from '@yoopta/table';
import { Columns3, Copy, GripVertical, Rows3, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';

import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';

/**
 * What the table commands want a path to be.
 *
 * They declare it as `Location | Span`, and that `Location` is the browser's —
 * the package's own .d.ts never imports Slate's, so TypeScript resolves it to
 * window.location's type and every real path is rejected. A slip in their
 * typings rather than in ours, corrected here once instead of at four call
 * sites.
 */
type CellPath = NonNullable<
    NonNullable<Parameters<typeof TableCommands.insertTableRow>[2]>['path']
>;

function cellPath(path: number[]): CellPath {
    return path as unknown as CellPath;
}

/**
 * How far to the left of the block the handle starts.
 *
 * The button is made exactly this wide, with the icon pushed to its left, so
 * its box runs from here up to the block's own edge. There is then no strip of
 * nothing in between for the pointer to fall through on the way over — see the
 * grace period in the component, which covers the rest.
 */
const HANDLE_WIDTH = 28;

/**
 * How wide the drop line is drawn.
 *
 * A fixed width rather than the block's, because the blocks it sits between can
 * be different widths — a full-width table above a short paragraph — and a line
 * that changed length as it moved would read as something other than "here".
 */
const DROP_LINE_WIDTH = 520;

/** Which block the mouse is over, and where to put the handle. */
interface Hovered {
    id: string;
    type: string;
    top: number;
    left: number;
}

/**
 * The six dots in front of a block.
 *
 * Without this there is no way to get hold of a block as a block. Text you can
 * at least select and delete by hand, but a picture, a divider or a table has
 * no text to put a caret in — the only way to remove one was to select the
 * lines around it and hope. So this is less a convenience than the missing half
 * of every block type that is not a paragraph.
 *
 * Our own rather than @yoopta/action-menu-list, which is still on the v4 API and
 * does not build against v6 at all. Rendered as a child of YooptaEditor, which
 * is what puts it inside the editor's context — the same arrangement the slash
 * menu and the toolbar use.
 */
export function DocumentBlockHandle() {
    const editor = useYooptaEditor();
    const { t } = useTranslate();

    const [hovered, setHovered] = useState<Hovered | null>(null);
    const [open, setOpen] = useState(false);

    /**
     * Where the line would land, while a block is being dragged.
     *
     * Null when nothing is being dragged, which is also how the rest of the
     * component knows to behave normally.
     */
    const [dropAt, setDropAt] = useState<{ index: number; top: number } | null>(
        null,
    );

    /** The pointer's starting point, until it has moved far enough to count. */
    const dragFrom = useRef<{ x: number; y: number; id: string } | null>(null);

    /**
     * Where the caret was when the menu was opened.
     *
     * The table commands work from the cursor: "add a row" means after the row
     * you are in. Opening a menu moves focus out of the editor and takes that
     * cursor with it, so by the time the menu item is clicked there is nothing
     * left to work from. Snapshotting it on mousedown — before the focus moves —
     * is what keeps "this row" meaning the row somebody was actually in.
     */
    const caret = useRef<number[] | null>(null);

    /**
     * Hiding is delayed, and that is what makes the handle reachable.
     *
     * Between the left edge of a block and the handle beside it there is a
     * strip of nothing, and a mouse crossing it is briefly over neither. Hiding
     * the instant that happens is a handle that cannot be clicked: it goes away
     * while you are on your way to it. A moment's grace covers the crossing,
     * and any movement back onto the block or onto the handle cancels it.
     */
    const hideTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const cancelHide = useCallback(() => {
        if (hideTimer.current !== null) {
            clearTimeout(hideTimer.current);
            hideTimer.current = null;
        }
    }, []);

    const scheduleHide = useCallback(() => {
        if (hideTimer.current !== null) {
            return;
        }

        hideTimer.current = setTimeout(() => {
            hideTimer.current = null;
            setHovered(null);
        }, 200);
    }, []);

    /**
     * How far the pointer has to travel before this is a drag and not a click.
     *
     * Without it every click is a one-pixel drag and the menu never opens
     * again — a mouse button is never pressed and released at exactly the same
     * coordinate.
     */
    const DRAG_THRESHOLD = 4;

    /**
     * Where a block would go if it were let go here.
     *
     * Measured against the middle of each block rather than its edges, so the
     * line flips to the other side of a block once the pointer is past half of
     * it — which is what makes dragging feel like it is following the hand
     * rather than lagging a block behind.
     */
    const dropTarget = useCallback((clientY: number) => {
        const blocks = Array.from(
            globalThis.document.querySelectorAll('[data-yoopta-block]'),
        );

        for (const [index, block] of blocks.entries()) {
            const rect = block.getBoundingClientRect();

            if (clientY < rect.top + rect.height / 2) {
                return { index, top: rect.top };
            }
        }

        const last = blocks.at(-1)?.getBoundingClientRect();

        return {
            index: blocks.length,
            top: last === undefined ? 0 : last.bottom,
        };
    }, []);

    useEffect(() => {
        const onMove = (event: MouseEvent) => {
            if (open || dragFrom.current !== null) {
                /*
                 * While the menu is open the handle belongs to the block it was
                 * opened on, however far the mouse has wandered since — and
                 * while a block is being dragged the pointer is by definition
                 * somewhere between blocks, which would otherwise hide the very
                 * handle being held.
                 */
                return;
            }

            const target = event.target as HTMLElement | null;

            /*
             * Moving onto the handle itself must not count as leaving.
             *
             * It sits to the left of the block, outside it, so the moment the
             * pointer arrives the event no longer comes from any block — and
             * hiding on that is a handle that vanishes exactly when it is
             * reached for. The button was gone before the click landed, which
             * is why nothing ever opened.
             */
            if (target?.closest('[data-block-handle]') != null) {
                cancelHide();

                return;
            }

            const block = target?.closest('[data-yoopta-block]');

            if (block === null || block === undefined) {
                scheduleHide();

                return;
            }

            cancelHide();

            const id = block.getAttribute('data-yoopta-block-id') ?? '';
            const rect = block.getBoundingClientRect();
            const next = {
                id,
                type: editor.children[id]?.type ?? '',
                /*
                 * A few pixels down from the top of the block rather than
                 * centred on it: a paragraph is one line and a table is twenty,
                 * and a handle floating in the middle of a tall block stops
                 * looking like it belongs to the line you are pointing at.
                 */
                top: rect.top + 2,
                left: rect.left - HANDLE_WIDTH,
            };

            // Only when something actually moved. Otherwise every pixel of
            // mouse travel is a re-render, over an editor somebody is typing in.
            setHovered((current) =>
                current !== null &&
                current.id === next.id &&
                current.top === next.top &&
                current.left === next.left
                    ? current
                    : next,
            );
        };

        /*
         * Anything that moves the page moves the block out from under the
         * handle, and a handle pointing at the wrong block is worse than none.
         * Hidden rather than recomputed: the next mouse movement puts it back,
         * and that is the same gesture that summoned it in the first place.
         */
        const onScroll = () => {
            if (!open) {
                setHovered(null);
            }
        };

        /*
         * On the document rather than on the editor, because the handle hangs
         * outside the editor's box and its own mouse moves have to be heard
         * too. Everything that is neither block nor handle schedules the hide.
         */
        globalThis.document.addEventListener('mousemove', onMove);
        globalThis.addEventListener('scroll', onScroll, true);

        return () => {
            globalThis.document.removeEventListener('mousemove', onMove);
            globalThis.removeEventListener('scroll', onScroll, true);
            cancelHide();
        };
    }, [editor, open, cancelHide, scheduleHide]);

    /** Take hold of the block, so it is visible which one this is about. */
    const select = useCallback(() => {
        if (hovered === null) {
            return;
        }

        const order = editor.children[hovered.id]?.meta.order;

        if (typeof order === 'number') {
            editor.setPath({ current: order, selected: [order] });
        }

        caret.current =
            Blocks.getBlockSlate(editor, { id: hovered.id })?.selection?.anchor
                .path ?? null;
    }, [editor, hovered]);

    /**
     * Take hold of the handle: maybe to open the menu, maybe to drag.
     *
     * Which of the two it is cannot be known yet, so nothing is decided here.
     * preventDefault does two jobs at once: it stops the browser selecting text
     * as the pointer sweeps across the document, and it stops Radix — which
     * opens its menu on pointerdown rather than on click — from opening the
     * menu the instant a drag begins. Opening is done by hand on pointer-up
     * instead, and only when the pointer never really went anywhere.
     */
    const onPointerDown = useCallback(
        (event: ReactPointerEvent<HTMLButtonElement>) => {
            if (hovered === null || event.button !== 0) {
                return;
            }

            event.preventDefault();
            select();

            dragFrom.current = {
                x: event.clientX,
                y: event.clientY,
                id: hovered.id,
            };

            // So the drag survives the pointer leaving the button, which
            // happens immediately — the button is narrower than a thumb.
            event.currentTarget.setPointerCapture(event.pointerId);
        },
        [hovered, select],
    );

    const onPointerMove = useCallback(
        (event: ReactPointerEvent<HTMLButtonElement>) => {
            const from = dragFrom.current;

            if (from === null) {
                return;
            }

            const travelled =
                Math.abs(event.clientX - from.x) +
                Math.abs(event.clientY - from.y);

            if (dropAt === null && travelled < DRAG_THRESHOLD) {
                return;
            }

            setDropAt(dropTarget(event.clientY));
        },
        [dropAt, dropTarget],
    );

    const onPointerUp = useCallback(
        (event: ReactPointerEvent<HTMLButtonElement>) => {
            const from = dragFrom.current;

            dragFrom.current = null;
            event.currentTarget.releasePointerCapture(event.pointerId);

            if (from === null) {
                return;
            }

            if (dropAt === null) {
                // Never moved: this was a click after all.
                setOpen(true);

                return;
            }

            const order = editor.children[from.id]?.meta.order;

            setDropAt(null);

            if (typeof order !== 'number') {
                return;
            }

            /*
             * The line sits *between* blocks, so an index of 3 means "third
             * gap" — and dragging downwards, the block leaves its own place
             * first, which pulls every gap below it up by one. Without that
             * correction a block dropped one place down lands exactly where it
             * already was, which reads as the drag having done nothing.
             */
            const target =
                dropAt.index > order ? dropAt.index - 1 : dropAt.index;

            if (target !== order) {
                editor.moveBlock(from.id, target);
            }
        },
        [dropAt, editor],
    );

    const runOnTable = useCallback(
        (command: (blockId: string, path: number[]) => void) => {
            if (hovered === null) {
                return;
            }

            /*
             * The first cell when nobody put a cursor anywhere. Predictable
             * beats clever: a row appears under the top row, which is somewhere
             * to undo from, rather than the command quietly doing nothing.
             */
            command(hovered.id, caret.current ?? [0, 0, 0]);
        },
        [hovered],
    );

    if (hovered === null || editor.readOnly) {
        return null;
    }

    const isTable = hovered.type === 'Table';

    /*
     * Whether this table already has a header row or column.
     *
     * Read off the element every time the menu draws rather than kept in state
     * here: the toggles are also reachable from elsewhere, and a copy would be
     * the thing that ends up showing "off" on a table with a header on it.
     */
    const tableProps = isTable
        ? ((
              editor.children[hovered.id]?.value?.[0] as
                  | { props?: { headerRow?: boolean; headerColumn?: boolean } }
                  | undefined
          )?.props ?? {})
        : {};

    return (
        <>
            {/*
                Where it would land. A line between two blocks rather than a
                highlight on one, because that is the question being answered:
                not "which block" but "which gap". Fixed and unclickable, so it
                never gets in the way of the pointer that is drawing it.
            */}
            {dropAt !== null && (
                <div
                    aria-hidden="true"
                    style={{
                        position: 'fixed',
                        top: dropAt.top - 1,
                        left: hovered.left,
                        width: DROP_LINE_WIDTH,
                    }}
                    className="pointer-events-none z-40 h-0.5 rounded bg-primary"
                />
            )}

            <DropdownMenu open={open} onOpenChange={setOpen}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        data-block-handle=""
                        aria-label={t('documents.block.label')}
                        title={t('documents.block.label')}
                        style={{
                            position: 'fixed',
                            top: hovered.top,
                            left: hovered.left,
                            width: HANDLE_WIDTH,
                        }}
                        /*
                         * Left-aligned inside a box as wide as the gap, so the
                         * button's own edge touches the block. The icon stays where
                         * it looks right; what grows is only what the pointer can
                         * land on.
                         */
                        className={cn(
                            'z-40 flex justify-start rounded py-0.5 text-muted-foreground/60 transition-colors hover:text-foreground',
                            dropAt === null ? 'cursor-grab' : 'cursor-grabbing',
                        )}
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        /*
                         * A pointer-up that never arrives — the browser took the
                         * gesture away, usually because a drag from the OS started.
                         * Without this the line would be left on screen and the next
                         * click would be read as the end of a drag.
                         */
                        onPointerCancel={() => {
                            dragFrom.current = null;
                            setDropAt(null);
                        }}
                    >
                        <GripVertical className="size-4 rounded hover:bg-muted" />
                    </button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="start" className="w-52">
                    {isTable && (
                        <>
                            <DropdownMenuItem
                                onSelect={() =>
                                    runOnTable((id, path) =>
                                        TableCommands.insertTableRow(
                                            editor,
                                            id,
                                            {
                                                path: cellPath(path),
                                                insertMode: 'after',
                                            },
                                        ),
                                    )
                                }
                            >
                                <Rows3 className="size-4" />
                                {t('documents.table.row_after')}
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                onSelect={() =>
                                    runOnTable((id, path) =>
                                        TableCommands.insertTableColumn(
                                            editor,
                                            id,
                                            {
                                                path: cellPath(path),
                                                insertMode: 'after',
                                            },
                                        ),
                                    )
                                }
                            >
                                <Columns3 className="size-4" />
                                {t('documents.table.column_after')}
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() =>
                                    runOnTable((id, path) =>
                                        TableCommands.deleteTableRow(
                                            editor,
                                            id,
                                            {
                                                path: cellPath(path),
                                            },
                                        ),
                                    )
                                }
                            >
                                <Rows3 className="size-4" />
                                {t('documents.table.row_delete')}
                            </DropdownMenuItem>

                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() =>
                                    runOnTable((id, path) =>
                                        TableCommands.deleteTableColumn(
                                            editor,
                                            id,
                                            { path: cellPath(path) },
                                        ),
                                    )
                                }
                            >
                                <Columns3 className="size-4" />
                                {t('documents.table.column_delete')}
                            </DropdownMenuItem>

                            <DropdownMenuSeparator />

                            {/*
                            Checkbox items rather than plain ones: a header is a
                            state the table is in, not an action, and a menu that
                            says "kopregel" without saying whether it has one
                            makes you click it to find out.
                        */}
                            <DropdownMenuCheckboxItem
                                checked={tableProps.headerRow === true}
                                onSelect={(event) => {
                                    // Radix closes on select; keeping it open lets
                                    // somebody set both headers in one visit.
                                    event.preventDefault();
                                    TableCommands.toggleHeaderRow(
                                        editor,
                                        hovered.id,
                                    );
                                }}
                            >
                                {t('documents.table.header_row')}
                            </DropdownMenuCheckboxItem>

                            <DropdownMenuCheckboxItem
                                checked={tableProps.headerColumn === true}
                                onSelect={(event) => {
                                    event.preventDefault();
                                    TableCommands.toggleHeaderColumn(
                                        editor,
                                        hovered.id,
                                    );
                                }}
                            >
                                {t('documents.table.header_column')}
                            </DropdownMenuCheckboxItem>

                            <DropdownMenuSeparator />
                        </>
                    )}

                    <DropdownMenuItem
                        onSelect={() =>
                            editor.duplicateBlock({ blockId: hovered.id })
                        }
                    >
                        <Copy className="size-4" />
                        {t('documents.block.duplicate')}
                    </DropdownMenuItem>

                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => {
                            editor.deleteBlock({ blockId: hovered.id });
                            setHovered(null);
                        }}
                    >
                        <Trash2 className="size-4" />
                        {t('documents.block.delete')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );
}
