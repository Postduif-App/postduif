import { useYooptaEditor } from '@yoopta/editor';
import { Bold, Code, Italic, Strikethrough, Underline } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ComponentType } from 'react';

import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';
import type { TranslationKey } from '@/types/translations';

/**
 * The marks the bar offers, under Yoopta's own names — which is what
 * editor.formats takes.
 */
const MARKS: {
    type: string;
    icon: ComponentType<{ className?: string }>;
    label: TranslationKey;
}[] = [
    { type: 'bold', icon: Bold, label: 'documents.toolbar.bold' },
    { type: 'italic', icon: Italic, label: 'documents.toolbar.italic' },
    {
        type: 'underline',
        icon: Underline,
        label: 'documents.toolbar.underline',
    },
    { type: 'strike', icon: Strikethrough, label: 'documents.toolbar.strike' },
    { type: 'code', icon: Code, label: 'documents.toolbar.code' },
];

/**
 * Select some text and a small bar appears above it.
 *
 * Our own rather than @yoopta/toolbar, which imports symbols that v6 no longer
 * exports and fails the build outright — see the epic's design notes.
 *
 * Rendered as a child of YooptaEditor, which is what puts it inside the
 * editor's context.
 */
export function DocumentToolbar() {
    const editor = useYooptaEditor();
    const { t } = useTranslate();

    const [at, setAt] = useState<{ top: number; left: number } | null>(null);

    /*
     * A counter rather than a copy of which marks are on.
     *
     * The answer lives in the editor and is asked for at render time with
     * isActive(); keeping a second copy here is how a bar ends up showing bold
     * as off on text that is bold, because ⌘B went through Yoopta's own
     * shortcut and never told us. This only forces the re-render that makes it
     * ask again.
     */
    const [, bump] = useState(0);

    const hide = useCallback(() => setAt(null), []);

    useEffect(() => {
        /*
         * On selectionchange rather than on the editor's own events: a
         * selection made by dragging the mouse produces no change and no path
         * change, and that is the most ordinary way there is to select a word.
         */
        const onSelectionChange = () => {
            const selection = globalThis.getSelection();

            if (
                selection === null ||
                selection.rangeCount === 0 ||
                selection.isCollapsed
            ) {
                hide();

                return;
            }

            // Only for a selection inside this editor. The page has other text.
            const container = editor.refElement;

            if (
                container === null ||
                !container.contains(selection.anchorNode)
            ) {
                hide();

                return;
            }

            const rect = selection.getRangeAt(0).getBoundingClientRect();

            if (rect.width === 0 && rect.height === 0) {
                hide();

                return;
            }

            setAt({
                // Above the selection, not below: below is where the next line
                // is, and covering what somebody is about to read is worse than
                // covering what they have just read.
                top: rect.top - 44,
                left: rect.left,
            });

            bump((count) => count + 1);
        };

        globalThis.document.addEventListener(
            'selectionchange',
            onSelectionChange,
        );
        editor.on('blur', hide);

        return () => {
            globalThis.document.removeEventListener(
                'selectionchange',
                onSelectionChange,
            );
            editor.off('blur', hide);
        };
    }, [editor, hide]);

    if (at === null || editor.readOnly) {
        return null;
    }

    return (
        <div
            style={{ position: 'fixed', top: at.top, left: at.left }}
            role="toolbar"
            aria-label={t('documents.toolbar.label')}
            className="z-50 flex items-center gap-0.5 rounded-lg border bg-popover p-1 shadow-md"
        >
            {MARKS.map(({ type, icon: Icon, label }) => {
                /*
                 * editor.formats is a map keyed by mark name, one TextFormat
                 * each — not a namespace with a type argument. A mark whose
                 * plugin is not loaded simply is not in it, so the button
                 * disappears rather than throwing.
                 */
                const format = editor.formats[type];

                if (format === undefined) {
                    return null;
                }

                const active = format.isActive();

                return (
                    <button
                        key={type}
                        type="button"
                        aria-label={t(label)}
                        title={t(label)}
                        aria-pressed={active}
                        /*
                         * onMouseDown with preventDefault, not onClick. A click
                         * moves focus to the button first, which collapses the
                         * very selection the mark is meant to apply to — so by
                         * the time onClick ran there would be nothing selected.
                         */
                        onMouseDown={(event) => {
                            event.preventDefault();
                            format.toggle();
                            bump((count) => count + 1);
                        }}
                        className={cn(
                            'rounded p-1.5 transition-colors hover:bg-muted',
                            active && 'bg-muted text-foreground',
                        )}
                    >
                        <Icon className="size-4" />
                    </button>
                );
            })}
        </div>
    );
}
