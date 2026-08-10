import { useEffect, useState } from 'react';
import type { ComponentType } from 'react';

import type { DocumentEditorProps } from '@/components/chat/document-yoopta';
import { useTranslate } from '@/hooks/use-translate';
import { cn } from '@/lib/utils';

/**
 * The boundary between the chat and the editor.
 *
 * Everything the editor needs — Yoopta, Slate, the plugins — is fetched only
 * when somebody actually opens a document, and this is the seam that makes that
 * possible. Two separate reasons, either of which would be enough on its own:
 *
 * It is around 620 kB gzipped, against roughly 60 kB for React itself. Loaded
 * with the rest of the chat, every person who only ever reads messages would
 * pay for a document they never open.
 *
 * And this application server-renders through Inertia. A contenteditable
 * editor has no server-side rendering — it needs a real DOM, a real selection
 * and a real caret — so it must not be reachable from the module graph the SSR
 * process walks.
 *
 * Fetched in an effect rather than through React.lazy and Suspense, and that is
 * the part worth reading twice. lazy() suspends during render, which on the
 * server means renderToString has to resolve it, which puts the import back on
 * the SSR path — the exact thing being avoided. An effect does not run on the
 * server at all, so the server renders the placeholder below and the browser
 * takes it from there. Slower by one paint, and correct.
 */
export function DocumentEditor(props: DocumentEditorProps) {
    const { t } = useTranslate();
    const [Editor, setEditor] =
        useState<ComponentType<DocumentEditorProps> | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let current = true;

        void import('@/components/chat/document-yoopta')
            .then((module) => {
                /*
                 * The extra arrow is not a slip. setState treats a function it
                 * is handed as an updater and would call the component instead
                 * of storing it, which renders as an unrelated crash inside the
                 * editor rather than here.
                 */
                if (current) {
                    setEditor(() => module.default);
                }
            })
            .catch(() => {
                if (current) {
                    setFailed(true);
                }
            });

        return () => {
            current = false;
        };
    }, []);

    if (failed) {
        /*
         * A chunk that will not load is usually a deploy that happened while
         * this tab was open. Say so and offer the reload, rather than leaving
         * somebody looking at a placeholder that never resolves.
         */
        return (
            <p className="px-1 py-6 text-sm text-muted-foreground">
                {t('documents.editor.failed')}{' '}
                <button
                    type="button"
                    className="underline underline-offset-2 hover:text-foreground"
                    onClick={() => window.location.reload()}
                >
                    {t('documents.editor.reload')}
                </button>
            </p>
        );
    }

    if (Editor === null) {
        return <DocumentEditorSkeleton />;
    }

    return <Editor {...props} />;
}

/**
 * Lines of about the right size in about the right place.
 *
 * Shaped like a document rather than shown as a spinner: this stands in for
 * something that is arriving, and a placeholder that already has the proportions
 * of what replaces it does not make the page jump when it does.
 */
function DocumentEditorSkeleton() {
    const widths = ['w-2/3', 'w-full', 'w-11/12', 'w-4/5', 'w-1/2'];

    return (
        <div
            className="space-y-3 px-1 py-2"
            aria-hidden="true"
            data-testid="document-editor-skeleton"
        >
            {widths.map((width, index) => (
                <div
                    key={width}
                    className={cn(
                        'h-4 animate-pulse rounded bg-muted',
                        width,
                        // The first line stands in for a heading.
                        index === 0 && 'h-6',
                    )}
                />
            ))}
        </div>
    );
}
