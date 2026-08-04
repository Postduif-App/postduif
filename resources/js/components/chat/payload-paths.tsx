import { useState } from 'react';

import { useTranslate } from '@/hooks/use-translate';

/**
 * Every path in a payload that could hold a message, with its value.
 *
 * Walks the whole thing, so a value four levels down is offered as readily as
 * one at the top. Lists are walked by index, which is what makes
 * "commits.0.message" something you can click rather than something you have to
 * know to type.
 *
 * Only the leaves that could actually be a message: a branch is not a message,
 * and offering one would be offering a path the endpoint will refuse.
 */
function collect(
    value: unknown,
    prefix: string,
    into: { path: string; preview: string }[],
): void {
    if (into.length >= MAX_PATHS) {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item, index) =>
            collect(
                item,
                prefix === '' ? String(index) : `${prefix}.${index}`,
                into,
            ),
        );

        return;
    }

    if (value !== null && typeof value === 'object') {
        Object.entries(value).forEach(([key, item]) =>
            collect(item, prefix === '' ? key : `${prefix}.${key}`, into),
        );

        return;
    }

    if (value === null || prefix === '') {
        return;
    }

    into.push({ path: prefix, preview: String(value) });
}

/**
 * Enough to find what you are looking for, few enough that the list stays
 * something you can read. A payload with hundreds of leaves is one you go and
 * look at yourself.
 */
const MAX_PATHS = 60;

/** How much of a value to show beside its path. */
const PREVIEW_LENGTH = 60;

export function PayloadPaths({
    payload,
    onPick,
}: {
    payload: Record<string, unknown>;
    /** Called with the dot path, so the field can be filled in for them. */
    onPick: (path: string) => void;
}) {
    const { t } = useTranslate();
    const [open, setOpen] = useState(false);

    if (payload._truncated === true) {
        return (
            <p className="text-xs text-muted-foreground">
                {t('chat_ui.payload.too_large')}
            </p>
        );
    }

    const paths: { path: string; preview: string }[] = [];

    collect(payload, '', paths);

    if (paths.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1">
            <button
                type="button"
                onClick={() => setOpen((was) => !was)}
                aria-expanded={open}
                className="self-start text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
            >
                {open ? t('chat_ui.payload.hide') : t('chat_ui.payload.show')}
            </button>

            {open && (
                <ul className="flex max-h-48 flex-col gap-0.5 overflow-y-auto rounded-md border p-2">
                    {paths.map((entry) => (
                        <li key={entry.path}>
                            <button
                                type="button"
                                onClick={() => onPick(entry.path)}
                                title={t('chat_ui.payload.use', {
                                    path: entry.path,
                                })}
                                className="flex w-full gap-2 rounded px-1 py-0.5 text-left text-xs hover:bg-muted"
                            >
                                <span className="shrink-0 font-mono text-primary">
                                    {entry.path}
                                </span>
                                <span className="truncate text-muted-foreground">
                                    {entry.preview.slice(0, PREVIEW_LENGTH)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
