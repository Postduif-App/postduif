import type { MessageLinkPreview } from '@/types/chat';

/**
 * What a shared link turned out to be, under the message that shared it.
 *
 * Capped and never stretched, the same rule an image attachment follows: a card
 * that sets the column's width drags the whole conversation with it. The image
 * is loaded from the other site rather than copied here — which means that site
 * learns the reader's address, and is the reason the whole feature is off until
 * a workspace turns it on.
 */
export function LinkPreviewCard({ preview }: { preview: MessageLinkPreview }) {
    return (
        <a
            href={preview.url}
            target="_blank"
            rel="noreferrer"
            className="mt-1.5 flex max-w-lg gap-3 rounded-lg border border-l-2 border-l-primary/40 p-3 transition-colors hover:bg-muted/50"
        >
            <span className="min-w-0 flex-1">
                {preview.siteName && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {preview.siteName}
                    </span>
                )}
                <span className="block truncate text-sm font-medium">
                    {preview.title}
                </span>
                {preview.description && (
                    <span className="mt-0.5 line-clamp-2 block text-xs text-muted-foreground">
                        {preview.description}
                    </span>
                )}
            </span>

            {preview.imageUrl && (
                <img
                    src={preview.imageUrl}
                    alt=""
                    loading="lazy"
                    // The other site is not trusted with a referrer either: it
                    // has no business knowing which channel this was pasted in.
                    referrerPolicy="no-referrer"
                    className="size-16 shrink-0 rounded object-cover"
                />
            )}
        </a>
    );
}
