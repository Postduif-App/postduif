import { useYooptaEditor } from '@yoopta/editor';
import type { PluginElementRenderProps } from '@yoopta/editor';

import { DocumentListRow } from '@/components/chat/document-list-row';
import { cn } from '@/lib/utils';

/**
 * A to-do item, with the box to tick.
 *
 * Yoopta's own todo-list element renders `<ul><li>text</li></ul>` and nothing
 * else — no checkbox, and the `checked` prop never reaches the DOM at all. So a
 * task looked exactly like an ordinary line, which is the one thing a task list
 * must not do. There is nothing to fix with CSS either: the state simply is not
 * there to style against.
 *
 * Hence a replacement render, hung on the plugin with .extend(). Everything
 * around the text is contentEditable={false}, or Slate would treat the checkbox
 * as part of the document and the caret would be able to land inside it.
 */
export function DocumentTodoItem({
    attributes,
    children,
    element,
    blockId,
}: PluginElementRenderProps) {
    const editor = useYooptaEditor();

    const checked =
        (element.props as { checked?: boolean } | undefined)?.checked === true;

    const toggle = () =>
        editor.updateElement({
            blockId,
            type: 'todo-list',
            props: { checked: !checked },
        });

    return (
        <ul
            {...attributes}
            data-element-type="todo-list"
            className="document-todo"
        >
            <DocumentListRow
                className={cn(checked && 'text-muted-foreground line-through')}
                marker={
                    <input
                        type="checkbox"
                        checked={checked}
                        aria-label={String(element.children?.[0] ?? '')}
                        /*
                         * onChange rather than onClick, so the keyboard reaches
                         * it too — and mousedown is stopped so ticking a box
                         * does not first move the caret out of the line.
                         */
                        onMouseDown={(event) => event.preventDefault()}
                        onChange={toggle}
                        className="size-4 cursor-pointer accent-primary"
                    />
                }
            >
                {children}
            </DocumentListRow>
        </ul>
    );
}
