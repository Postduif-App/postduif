/*
 * pdf.js keeps a document object that is deliberately mutable and long-lived —
 * pages are fetched from it lazily, render tasks are cancelled on it, and it
 * holds a worker connection that must be destroyed by hand. The React Compiler
 * is on for this project, and memoisation keyed on "did this value change"
 * reads that single stable reference as never having changed.
 *
 * Same reasoning as the Yoopta wrapper, one file over.
 */
'use no memo';

import * as pdfjs from 'pdfjs-dist';
import type { PDFDocumentProxy, PDFPageProxy } from 'pdfjs-dist';
import { useEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';

/*
 * The worker, built by Vite as an asset of this application.
 *
 * Not from a CDN, and that is not a preference. There is a content security
 * policy on this application, and — far more to the point — the thing being
 * handed to this worker is somebody's contract. A document that is private
 * enough to sit behind a policy on the private disk does not then get streamed
 * through a script fetched from a third party.
 *
 * new URL(..., import.meta.url) is what makes Vite notice it and emit it under
 * /build. A bare string would be a path that only exists in node_modules.
 *
 * EEN EIS AAN DE WEBSERVER, EN DIE IS AL EEN KEER GEMIST
 * Vite houdt de extensie aan, dus dit bestand komt als .mjs op de schijf. Veel
 * nginx-installaties hebben daar geen MIME-regel voor en serveren hem als
 * application/octet-stream — en een browser weigert daar een module-worker uit
 * te starten. Met X-Content-Type-Options: nosniff, dat deze applicatie terecht
 * meestuurt, is er ook niets om op terug te vallen.
 *
 * Het scherm blijft dan leeg met "Loading Worker ... was blocked because of a
 * disallowed MIME type". Op postduif.app is dat opgelost met een location-blok
 * dat default_type text/javascript zet voor .mjs; let op dat een `types { }`-blok
 * daar juist verkeerd is, want dat vervangt de geërfde MIME-map in plaats van
 * hem aan te vullen.
 */
pdfjs.GlobalWorkerOptions.workerSrc = new URL(
    'pdfjs-dist/build/pdf.worker.min.mjs',
    import.meta.url,
).toString();

export interface RenderedPageSize {
    width: number;
    height: number;
}

export interface ContractPdfProps {
    /** Where the document is fetched from — the policy-guarded source route. */
    url: string;
    /** How many pages the server counted at upload. */
    pageCount: number;
    /**
     * How wide a page should be drawn, in CSS pixels. The zoom control changes
     * this and nothing else: everything laid over the page is stored as
     * fractions, so a wider page moves nothing.
     */
    pageWidth: number;
    /**
     * Told the size each page came out at, so whatever is drawn on top can put
     * itself in the right place. Fires per page, because a contract may mix A4
     * with a scanned landscape appendix.
     */
    onPageRendered?: (page: number, size: RenderedPageSize) => void;
    /** Drawn over each page — the boxes, in other words. */
    overlay?: (page: number, size: RenderedPageSize) => React.ReactNode;
    onFailed?: () => void;
    /**
     * Whether the fetch carries the session cookie.
     *
     * True for the editor, whose source route sits behind the session and a
     * policy — without it pdf.js arrives without the cookie and is answered
     * with a redirect to the login page, which it then tries to parse as a PDF.
     *
     * False for the public signing page, where a token in the path is the whole
     * credential and there is no session to send. Left off rather than sent
     * anyway: a signer who happens to be logged in to some workspace should not
     * have that ride along with a request that has nothing to do with it.
     */
    withCredentials?: boolean;
}

/**
 * The contract, page by page, with room to put things on top.
 *
 * Every page is rendered to its own canvas at the current width. Nothing here
 * knows what a field is; it draws the document and reports how big each page
 * came out, which is all the editor needs to place a box on it.
 */
export default function ContractPdf({
    url,
    pageCount,
    pageWidth,
    onPageRendered,
    overlay,
    onFailed,
    withCredentials = false,
}: ContractPdfProps) {
    const [document, setDocument] = useState<PDFDocumentProxy | null>(null);

    useEffect(() => {
        let current = true;

        // See the prop's own note for why this is not simply always on.
        const task = pdfjs.getDocument({ url, withCredentials });

        task.promise
            .then((loaded) => {
                if (current) {
                    setDocument(loaded);
                }

                /*
                 * Nothing to tear down in the else branch, although it looks
                 * like there should be. The loading task owns the document and
                 * the worker connection — PDFDocumentProxy has no destroy() of
                 * its own since pdf.js 6 — and the cleanup below has already
                 * destroyed the task by the time this can be reached.
                 */
            })
            .catch(() => {
                if (current) {
                    onFailed?.();
                }
            });

        return () => {
            current = false;

            // Takes the document and the worker connection with it. This is
            // also what runs when the url changes, so the previous document is
            // never left open.
            void task.destroy();
        };
        // onFailed is left out deliberately: a caller that passes an inline
        // arrow would otherwise refetch the whole document on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, withCredentials]);

    if (document === null) {
        return <ContractPdfSkeleton pageCount={pageCount} width={pageWidth} />;
    }

    return (
        <div className="flex flex-col items-center gap-6">
            {Array.from({ length: document.numPages }, (_, index) => (
                <ContractPdfPage
                    key={index + 1}
                    document={document}
                    page={index + 1}
                    width={pageWidth}
                    onRendered={onPageRendered}
                    overlay={overlay}
                />
            ))}
        </div>
    );
}

/**
 * One page on one canvas, with whatever the editor wants on top of it.
 *
 * The canvas is drawn at the device pixel ratio and then scaled back down in
 * CSS, which is the difference between a contract you can read and a blurred
 * one on any modern screen. The CSS size is the one that matters to everything
 * else: it is what a box's fractions are multiplied by.
 */
function ContractPdfPage({
    document,
    page,
    width,
    onRendered,
    overlay,
}: {
    document: PDFDocumentProxy;
    page: number;
    width: number;
    onRendered?: (page: number, size: RenderedPageSize) => void;
    overlay?: (page: number, size: RenderedPageSize) => React.ReactNode;
}) {
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const [size, setSize] = useState<RenderedPageSize | null>(null);

    useEffect(() => {
        let current = true;
        let task: ReturnType<PDFPageProxy['render']> | null = null;

        void document.getPage(page).then((loaded) => {
            if (!current || canvas.current === null) {
                return;
            }

            /*
             * Scale so the page comes out exactly `width` CSS pixels wide,
             * whatever it measures in PDF points. A4 and Letter differ, and a
             * scanned appendix may be neither — asking the page for its own
             * size at scale 1 and dividing is what makes them all line up in
             * one column.
             */
            const natural = loaded.getViewport({ scale: 1 });
            const scale = width / natural.width;
            const viewport = loaded.getViewport({ scale });

            const ratio = window.devicePixelRatio || 1;
            const context = canvas.current.getContext('2d');

            if (context === null) {
                return;
            }

            canvas.current.width = Math.floor(viewport.width * ratio);
            canvas.current.height = Math.floor(viewport.height * ratio);
            canvas.current.style.width = `${viewport.width}px`;
            canvas.current.style.height = `${viewport.height}px`;

            context.setTransform(ratio, 0, 0, ratio, 0, 0);

            task = loaded.render({ canvas: canvas.current, viewport });

            void task.promise
                .then(() => {
                    if (!current) {
                        return;
                    }

                    const rendered = {
                        width: viewport.width,
                        height: viewport.height,
                    };

                    setSize(rendered);
                    onRendered?.(page, rendered);
                })
                .catch(() => {
                    /*
                     * A cancelled render is the ordinary case, not a fault: it
                     * is what happens every time somebody changes the zoom
                     * while a page is still being drawn.
                     */
                });
        });

        return () => {
            current = false;
            task?.cancel();
        };
        // onRendered is left out for the reason onFailed is above.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [document, page, width]);

    return (
        <div
            className="relative shadow-sm ring-1 ring-border"
            data-page={page}
            data-testid={`contract-page-${page}`}
        >
            <canvas ref={canvas} className="block" />

            {/*
                Only once the page has been measured. An overlay placed against
                a size of zero would put every box in the top-left corner for
                one frame, which reads as the fields jumping into place.
            */}
            {size !== null && overlay?.(page, size)}
        </div>
    );
}

/**
 * Pages of about the right shape in about the right place.
 *
 * A4's proportions rather than a spinner: this stands in for something that is
 * arriving, and a placeholder already the shape of what replaces it keeps the
 * page from jumping when it does.
 */
function ContractPdfSkeleton({
    pageCount,
    width,
}: {
    pageCount: number;
    width: number;
}) {
    return (
        <div className="flex flex-col items-center gap-6" aria-hidden="true">
            {Array.from({ length: Math.min(pageCount, 3) }, (_, index) => (
                <div
                    key={index}
                    className={cn('animate-pulse rounded bg-muted')}
                    style={{ width, height: width * 1.414 }}
                />
            ))}
        </div>
    );
}
