import { Download, X } from 'lucide-react';
import { useEffect } from 'react';

/**
 * One picture, as large as the screen allows.
 *
 * Its own overlay rather than a Dialog: a dialog is a panel with a width, and
 * what this needs is the whole viewport with the image centred in it. What it
 * borrows from a dialog is the behaviour that matters — Escape closes it, the
 * background does not scroll, and clicking beside the picture closes it too.
 */
export function ImageLightbox({
    src,
    alt,
    downloadUrl,
    onClose,
}: {
    src: string;
    alt: string;
    /** The original rather than the preview: this is the "save it" link. */
    downloadUrl: string;
    onClose: () => void;
}) {
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        // The page behind must not scroll away under the picture.
        const previous = document.body.style.overflow;

        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        return () => {
            document.body.style.overflow = previous;
            window.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label={alt || 'Afbeelding'}
            onClick={onClose}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
        >
            <div className="absolute top-4 right-4 flex items-center gap-2">
                <a
                    href={downloadUrl}
                    download
                    // The click would otherwise reach the overlay and close it
                    // before the download starts.
                    onClick={(event) => event.stopPropagation()}
                    title="Downloaden"
                    aria-label="Downloaden"
                    className="rounded-md bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                >
                    <Download className="size-5" />
                </a>
                <button
                    type="button"
                    onClick={onClose}
                    title="Sluiten"
                    aria-label="Sluiten"
                    className="rounded-md bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                >
                    <X className="size-5" />
                </button>
            </div>

            {/*
                The picture itself does not close it: dragging to look at a
                detail should not dismiss what you are looking at.
            */}
            <img
                src={src}
                alt={alt}
                onClick={(event) => event.stopPropagation()}
                className="max-h-full max-w-full rounded-lg object-contain"
            />
        </div>
    );
}
