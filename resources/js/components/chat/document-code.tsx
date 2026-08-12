import { Elements, useYooptaEditor } from '@yoopta/editor';
import type {
    DecoratorFn,
    LeafDecoratorRenderFn,
    PluginElementRenderProps,
    SlateElement,
    YooEditor,
} from '@yoopta/editor';
import type { ReactNode } from 'react';

import { useTranslate } from '@/hooks/use-translate';
import {
    highlightedNow,
    requestHighlight,
    tokenRanges,
} from '@/lib/code-highlight-cache';
import { LANGUAGE_LABELS } from '@/lib/highlight';

/** The leaf property a coloured stretch of code is marked with. */
const TOKEN = 'pdCodeToken';

/**
 * A code block in a document.
 *
 * Editable, which is what makes it a different problem from the code block in a
 * message: that one is read-only, so it can be handed finished HTML. Here Slate
 * owns the DOM and would overwrite anything written into it on the next
 * keystroke — quite apart from that being dangerouslySetInnerHTML on text
 * somebody is in the middle of typing.
 *
 * So the colours arrive as Slate decorations instead: ranges over the text,
 * rendered as leaves. Slate keeps the text, the caret and the undo history; we
 * only say which stretches are which colour.
 */
export function DocumentCode({
    attributes,
    children,
    element,
    blockId,
}: PluginElementRenderProps) {
    const editor = useYooptaEditor();
    const { t } = useTranslate();

    const language =
        typeof element.props?.language === 'string'
            ? element.props.language
            : null;

    return (
        /*
         * The attributes go on the outermost node, and that is not a style
         * choice. They carry Slate's ref and its data-slate-node marker, which
         * is how a DOM position is turned back into a position in the document.
         * On a nested element — with other DOM as its sibling — Slate cannot
         * make that map: the caret lands nowhere and typing goes nowhere,
         * which is exactly what happened the first time this was written.
         */
        <div {...attributes} className="document-code group relative">
            {/*
                Everything that is not the code itself is held out of the
                editable. Without contentEditable={false} Slate would try to
                place a caret among the options and the arrow keys would walk
                into the picker.
            */}
            <div
                contentEditable={false}
                className="absolute top-1.5 right-1.5 z-10 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
            >
                <select
                    className="rounded border border-border bg-background px-1.5 py-0.5 text-xs text-muted-foreground"
                    value={language ?? ''}
                    aria-label={t('documents.code.language')}
                    onChange={(event) => {
                        Elements.updateElement(editor, {
                            blockId,
                            type: 'code',
                            props: {
                                language:
                                    event.target.value === ''
                                        ? null
                                        : event.target.value,
                            },
                        });
                    }}
                >
                    <option value="">{t('documents.code.plain')}</option>
                    {Object.entries(LANGUAGE_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </div>

            <pre className="pd-code overflow-x-auto rounded-md bg-muted px-4 py-3 text-sm">
                <code>{children}</code>
            </pre>
        </div>
    );
}

/**
 * Which stretches of a code block get which colour.
 *
 * Registered on the editor rather than on the plugin, because that is where
 * Yoopta keeps decorators — a Map the core walks on every decorate. It is
 * therefore called for every text node in the document, not only for code, so
 * the first thing it does is establish that this one is code and leave
 * otherwise.
 *
 * Note what it does not do: wait. The tokens are whatever the cache already
 * has, and a miss returns no ranges and starts the work. Slate's decorate is
 * synchronous and cannot be made to wait for a grammar to download.
 */
export function codeDecorator(editor: YooEditor): DecoratorFn {
    return (blockId, [node, path]) => {
        if (!isTextNode(node)) {
            return [];
        }

        const block = editor.children[blockId];

        if (block === undefined || block.type !== 'Code') {
            return [];
        }

        const element = block.value[0] as SlateElement | undefined;
        const language =
            typeof element?.props?.language === 'string'
                ? element.props.language
                : null;

        const tokens = highlightedNow(node.text, language);

        if (tokens === null) {
            /*
             * Ask, and let the block redraw when the answer lands.
             *
             * The event rather than a transform, and that distinction is the
             * whole trick: every block listens for 'decorations:change' and
             * re-runs its decorate, while a Slate transform would put "the
             * colours arrived" in the undo history as something the writer did.
             */
            requestHighlight(node.text, language, () =>
                editor.emit('decorations:change', undefined),
            );

            return [];
        }

        return tokenRanges(tokens)
            .filter((range) => range.start < node.text.length)
            .map((range) => ({
                anchor: { path, offset: range.start },
                focus: { path, offset: Math.min(range.end, node.text.length) },
                [TOKEN]: range.style,
            }));
    };
}

/**
 * Paint one decorated stretch.
 *
 * Runs for every leaf in the document — the core hands each of them to every
 * registered leaf decorator in turn — so anything that is not ours is passed
 * straight back untouched.
 *
 * The style is two custom properties rather than a colour, exactly as a code
 * block in a message: `.pd-code span` in app.css picks between them, so the
 * block follows a theme switch without anything here re-running.
 */
export const codeLeafDecorator: LeafDecoratorRenderFn = (leaf, children) => {
    const style = (leaf as Record<string, unknown>)[TOKEN];

    if (style === undefined || style === null) {
        return children;
    }

    return (
        <span style={style as Record<string, string>}>
            {children as ReactNode}
        </span>
    );
};

function isTextNode(node: unknown): node is { text: string } {
    return (
        typeof node === 'object' &&
        node !== null &&
        'text' in node &&
        typeof (node as { text: unknown }).text === 'string'
    );
}
