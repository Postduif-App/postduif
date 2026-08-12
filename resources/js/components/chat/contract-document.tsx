import { useEffect, useState } from 'react';
import type { ComponentType } from 'react';

import type { ContractPdfProps } from '@/components/chat/contract-pdf';
import { useTranslate } from '@/hooks/use-translate';

/**
 * The boundary between the chat and pdf.js.
 *
 * The same seam the document editor has, for the same two reasons and one
 * extra.
 *
 * pdf.js with its worker is a few hundred kilobytes, and every person who never
 * opens a contract would otherwise pay for it on every page of the chat.
 *
 * And this application server-renders through Inertia. pdf.js needs a canvas, a
 * Worker and a devicePixelRatio, none of which exist in the render process — so
 * it must not be reachable from the module graph the server walks. That is not
 * only about the component: the module sets GlobalWorkerOptions.workerSrc at
 * import time, using import.meta.url, which is exactly the sort of thing that
 * turns a render into a stack trace.
 *
 * Fetched in an effect rather than through React.lazy, and that is the part
 * worth reading twice. lazy() suspends during render, which on the server means
 * renderToString has to resolve it — putting the import back on the SSR path,
 * the exact thing being avoided. An effect never runs on the server, so the
 * server renders the placeholder and the browser takes it from there.
 */
export function ContractDocument(props: ContractPdfProps) {
    const { t } = useTranslate();
    const [Pdf, setPdf] = useState<ComponentType<ContractPdfProps> | null>(
        null,
    );
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let current = true;

        void import('@/components/chat/contract-pdf')
            .then((module) => {
                /*
                 * The extra arrow is not a slip. setState treats a function it
                 * is handed as an updater and would call the component instead
                 * of storing it — which surfaces as an unrelated crash inside
                 * pdf.js rather than here.
                 */
                if (current) {
                    setPdf(() => module.default);
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
                {t('contracts.editor.failed')}{' '}
                <button
                    type="button"
                    className="underline underline-offset-2 hover:text-foreground"
                    onClick={() => window.location.reload()}
                >
                    {t('contracts.editor.reload')}
                </button>
            </p>
        );
    }

    if (Pdf === null) {
        return (
            <div
                className="animate-pulse rounded bg-muted"
                style={{
                    width: props.pageWidth,
                    height: props.pageWidth * 1.414,
                }}
                aria-hidden="true"
                data-testid="contract-document-skeleton"
            />
        );
    }

    return <Pdf {...props} />;
}
