import { useYooptaEditor } from '@yoopta/editor';
import {
    Code,
    Heading1,
    Heading2,
    Heading3,
    Image as ImageIcon,
    Info,
    List,
    ListChecks,
    ListOrdered,
    Minus,
    Paperclip,
    Quote,
    Table as TableIcon,
    Type,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import type { ComponentType } from 'react';

import { DOCUMENT_BLOCK_MENU } from '@/components/chat/document-blocks';
import type { DocumentBlockChoice } from '@/components/chat/document-blocks';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { TranslationKey } from '@/types/translations';

/** The caret's rectangle, in viewport coordinates. */
interface Caret {
    top: number;
    bottom: number;
    left: number;
}

/** Where the menu goes once it has been measured against the window. */
interface Placement {
    top: number;
    left: number;
    maxHeight: number;
}

/**
 * The icons, resolved from the names the block list carries.
 *
 * The list holds names rather than components so that document-blocks.ts stays
 * data — it is imported by things that only want to know which blocks exist,
 * and a module that imports a dozen icons is no longer that.
 */
const ICONS: Record<string, ComponentType<{ className?: string }>> = {
    Type,
    Heading1,
    Heading2,
    Heading3,
    List,
    ListOrdered,
    ListChecks,
    Quote,
    Info,
    Code,
    Minus,
    ImageIcon,
    Paperclip,
    TableIcon,
};

/**
 * Type "/" on an empty line and pick a block.
 *
 * Our own rather than @yoopta/action-menu-list, which is still on the v4 API
 * and does not build against v6 at all — see the epic's design notes.
 *
 * Built by hand rather than on cmdk, which the rest of this application uses
 * for its palette, and the reason is worth stating. cmdk drives its selection
 * from an input of its own that holds focus. Here there is no input — the line
 * being typed into *is* the input, and focus has to stay in the editor or the
 * caret moves and the selection collapses. So cmdk never received the arrow
 * keys, and clicking an item pulled focus out of Slate, which fired the blur
 * that closes this menu before the choice ever landed. A plain list with its
 * own key handling has neither problem, and is shorter than the workarounds.
 *
 * Rendered as a child of YooptaEditor, which is what puts it inside the
 * editor's context and lets useYooptaEditor() find it.
 */
export function DocumentSlashMenu() {
    const editor = useYooptaEditor();
    const { t } = useTranslate();

    const [query, setQuery] = useState<string | null>(null);
    const [at, setAt] = useState<Caret | null>(null);
    const [active, setActive] = useState(0);
    const list = useRef<HTMLDivElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    /*
     * Where the menu ends up, worked out after it has been measured.
     *
     * Null on the first render of a given caret position, which is why the menu
     * starts hidden: it has to exist to be measured, and a menu that is briefly
     * drawn in the wrong place and then jumps is worse than one that appears a
     * frame later in the right one.
     */
    const [placed, setPlaced] = useState<Placement | null>(null);

    const close = useCallback(() => {
        setQuery(null);
        setAt(null);
        setActive(0);
        setPlaced(null);
    }, []);

    /*
     * Memoised because the key handler below depends on it, and a fresh array
     * every render would tear down and re-register that listener on every
     * keystroke — during the one interaction where keystrokes are the point.
     */
    const matches = useMemo(
        () =>
            query === null
                ? []
                : DOCUMENT_BLOCK_MENU.filter((choice) =>
                      fits(t(label(choice)), query),
                  ),
        [query, t],
    );

    /*
     * Read off the document rather than off keystrokes.
     *
     * A keydown listener would have to work out on its own what a "/" means
     * after a paste, an undo, or an IME composition — three cases where the
     * character arrives without the keystroke that would suggest it. Asking the
     * document what the current block now says is the same question with none
     * of those exceptions.
     */
    useEffect(() => {
        const onChange = () => {
            const block = editor.getBlock({ at: editor.path.current });

            // Only on a paragraph: "/" partway through a heading is a slash.
            if (block === null || block.type !== 'Paragraph') {
                close();

                return;
            }

            const text = editor.getPlainText({ [block.id]: block });

            /*
             * A space ends it. Somebody writing "en/of" gets a menu for one
             * keystroke, which is a flicker; somebody writing "/ per stuk" gets
             * one that stays until they delete the line, which is a nuisance.
             */
            if (!text.startsWith('/') || text.includes(' ')) {
                close();

                return;
            }

            setQuery(text.slice(1));
            setAt(caret());
            // Back to the top: what was third for "ko" is a different third
            // for "kop".
            setActive(0);
        };

        editor.on('change', onChange);
        editor.on('blur', close);

        return () => {
            editor.off('change', onChange);
            editor.off('blur', close);
        };
    }, [editor, close]);

    const choose = useCallback(
        (choice: DocumentBlockChoice) => {
            close();

            /*
             * preserveContent: false is what throws the "/kop" away. With it
             * left on, the block would become a heading whose text is the
             * command that made it one.
             */
            editor.toggleBlock(choice.type, {
                preserveContent: false,
                focus: true,
            });
        },
        [close, editor],
    );

    /*
     * The keys, taken before the editor sees them.
     *
     * Capture phase and stopPropagation, because Slate listens on the same keys
     * and would move the caret a line down while this menu thinks it moved a
     * row down. For as long as the menu is open those keys belong to it and to
     * nothing else; everything it does not claim — every letter, every
     * backspace — falls through and keeps narrowing the query.
     */
    useEffect(() => {
        if (query === null) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                // Without touching the text: "/" stays on the line as the
                // character somebody may actually have meant.
                event.preventDefault();
                event.stopPropagation();
                close();

                return;
            }

            if (matches.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopPropagation();

                const step = event.key === 'ArrowDown' ? 1 : -1;

                // Wrapping, so holding one arrow reaches everything without
                // having to know which end you are at.
                setActive(
                    (index) => (index + step + matches.length) % matches.length,
                );

                return;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                event.stopPropagation();
                choose(matches[active] ?? matches[0]);
            }
        };

        globalThis.document.addEventListener('keydown', onKey, true);

        return () =>
            globalThis.document.removeEventListener('keydown', onKey, true);
    }, [query, matches, active, choose, close]);

    /*
     * Above the caret when there is no room below it.
     *
     * Measured rather than guessed, because the menu is between one and eleven
     * rows tall depending on what has been typed — an estimate would flip on
     * the wrong ones. useLayoutEffect so the measuring and the correction both
     * happen before the browser paints, and nothing is ever seen in the wrong
     * place.
     */
    useLayoutEffect(() => {
        const box = menu.current;

        if (at === null || box === null) {
            return;
        }

        const gap = 6;
        const margin = 8;

        const below = globalThis.innerHeight - at.bottom - gap - margin;
        const above = at.top - gap - margin;

        /*
         * Flip only when it actually helps. Near the bottom of a short window
         * both sides can be too small, and in that case staying below and
         * scrolling reads better than jumping over the line being typed.
         */
        const flip = box.offsetHeight > below && above > below;

        setPlaced({
            top: flip
                ? Math.max(
                      margin,
                      at.top - gap - Math.min(box.offsetHeight, above),
                  )
                : at.bottom + gap,
            // Clamped, so a caret near the right edge of a narrow panel does
            // not push the menu off the screen.
            left: Math.min(
                Math.max(margin, at.left),
                globalThis.innerWidth - box.offsetWidth - margin,
            ),
            maxHeight: Math.max(140, flip ? above : below),
        });
    }, [at, matches.length]);

    // Keep the highlighted row in view when the arrows walk past the edge.
    useEffect(() => {
        list.current
            ?.querySelector('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    if (query === null || at === null) {
        return null;
    }

    return (
        <div
            ref={menu}
            /*
             * Fixed, positioned from the caret's viewport rectangle. Absolute
             * inside the editor would have to account for the scroll of every
             * ancestor between here and the panel, and there are three.
             */
            style={{
                position: 'fixed',
                top: placed?.top ?? at.bottom + 6,
                left: placed?.left ?? at.left,
                // Hidden rather than unmounted until it has been measured:
                // there has to be something to measure.
                visibility: placed === null ? 'hidden' : 'visible',
            }}
            className="z-50 w-64 overflow-hidden rounded-lg border bg-popover py-1 shadow-md"
            /*
             * The whole menu refuses focus. A mousedown that moved focus out of
             * the editor would collapse the selection and fire the editor's
             * blur — which closes this menu — before the click that chose
             * anything ever completed.
             */
            onMouseDown={(event) => event.preventDefault()}
        >
            {matches.length === 0 ? (
                <p className="px-3 py-2 text-sm text-muted-foreground">
                    {t('documents.slash.empty')}
                </p>
            ) : (
                <div
                    ref={list}
                    className="overflow-y-auto"
                    style={{ maxHeight: placed?.maxHeight ?? 288 }}
                >
                    {matches.map((choice, index) => {
                        const Icon = ICONS[choice.icon] ?? Type;
                        const isActive = index === active;

                        return (
                            <button
                                key={choice.type}
                                type="button"
                                data-active={isActive}
                                /*
                                 * Pointer, not mouse: hovering moves the
                                 * highlight, so the keyboard and the mouse never
                                 * disagree about what Enter would pick.
                                 */
                                onPointerMove={() => setActive(index)}
                                onClick={() => choose(choice)}
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm',
                                    isActive &&
                                        'bg-accent text-accent-foreground',
                                )}
                            >
                                <Icon className="size-4 text-muted-foreground" />
                                {t(label(choice))}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function label(choice: DocumentBlockChoice): TranslationKey {
    return `documents.slash.blocks.${choice.key}` as TranslationKey;
}

/**
 * Whether a block's name answers to what has been typed so far.
 *
 * Accent- and case-insensitive: somebody reaching for "citaat" should not have
 * to know whether the list spells it with a capital.
 */
function fits(name: string, query: string): boolean {
    if (query === '') {
        return true;
    }

    const fold = (value: string) =>
        value
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase();

    return fold(name).includes(fold(query));
}

/**
 * Where the caret is, in viewport coordinates, with the menu hung just below it.
 *
 * Read from the live DOM selection rather than from the editor's own selection
 * model: what is wanted here is a rectangle on screen, and only the browser
 * knows where a character ended up after wrapping.
 */
function caret(): Caret | null {
    const selection = globalThis.getSelection();

    if (selection === null || selection.rangeCount === 0) {
        return null;
    }

    const rect = selection.getRangeAt(0).getBoundingClientRect();

    // A collapsed range in an empty block can measure zero on both axes, which
    // would pin the menu to the top-left corner of the window.
    if (rect.top === 0 && rect.left === 0) {
        return null;
    }

    return { top: rect.top, bottom: rect.bottom, left: rect.left };
}
