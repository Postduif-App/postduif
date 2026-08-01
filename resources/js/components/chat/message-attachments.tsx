import { Download, FileText, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { cn } from '@/lib/utils';
import type { MessageAttachment } from '@/types/chat';

/** Bytes as somebody reads them: "1,4 MB", not "1468006". */
function readableSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    return kb < 1024
        ? `${Math.round(kb)} KB`
        : `${(kb / 1024).toLocaleString('nl-NL', { maximumFractionDigits: 1 })} MB`;
}

/**
 * The same file, asked for as a download.
 *
 * A query rather than a second route: it is the same bytes behind the same
 * permission check, and only the disposition header differs.
 */
function downloadUrl(attachment: MessageAttachment): string {
    return `${attachment.url}${attachment.url.includes('?') ? '&' : '?'}download=1`;
}

/**
 * The button that appears over an image or a video on hover.
 *
 * Over rather than beside: the thing you want to download is what you are
 * looking at, and a row of controls under every image would add a line of
 * furniture to every message that has one.
 */
function DownloadOverlay({ attachment }: { attachment: MessageAttachment }) {
    return (
        <a
            href={downloadUrl(attachment)}
            download={attachment.name}
            onClick={(event) => event.stopPropagation()}
            title={`${attachment.name} downloaden`}
            aria-label={`${attachment.name} downloaden`}
            className="absolute top-2 right-2 rounded-md bg-background/85 p-1.5 text-foreground opacity-0 shadow-sm backdrop-blur transition-opacity group-hover/attachment:opacity-100 focus-visible:opacity-100"
        >
            <Download className="size-4" />
        </a>
    );
}

function isImage(attachment: MessageAttachment): boolean {
    // svg is deliberately absent: no workspace setting lets one in, and the
    // route refuses to render one in place. See AttachmentType.
    return (
        attachment.mimeType?.startsWith('image/') === true &&
        attachment.mimeType !== 'image/svg+xml'
    );
}

function isVideo(attachment: MessageAttachment): boolean {
    return attachment.mimeType?.startsWith('video/') === true;
}

function isAudio(attachment: MessageAttachment): boolean {
    return attachment.mimeType?.startsWith('audio/') === true;
}

/**
 * An image in the conversation, at a size that leaves the conversation readable.
 *
 * Capped in both directions and never stretched: a portrait screenshot that
 * filled the column would push everything said after it off the screen, and a
 * wide one that set the column's width would drag the whole thread with it.
 * Clicking opens the original, which is what the cap is a substitute for.
 */
function ImageAttachment({ attachment }: { attachment: MessageAttachment }) {
    const [failed, setFailed] = useState(false);

    if (failed) {
        return <FileAttachment attachment={attachment} />;
    }

    return (
        <div className="relative w-fit max-w-full">
            <a
                href={attachment.url}
                target="_blank"
                rel="noreferrer"
                className="block overflow-hidden rounded-lg border"
            >
                <img
                    src={attachment.previewUrl ?? attachment.url}
                    alt={attachment.name}
                    loading="lazy"
                    onError={() => setFailed(true)}
                    className="max-h-80 w-auto max-w-full object-contain"
                />
            </a>
            <DownloadOverlay attachment={attachment} />
        </div>
    );
}

/**
 * Anything the conversation does not show in place: a row with the name, the
 * type and the size, which is what somebody needs to decide whether to open it.
 */
function FileAttachment({ attachment }: { attachment: MessageAttachment }) {
    return (
        <div
            className={cn(
                'flex max-w-full items-center gap-3 rounded-lg border p-2.5 text-sm transition-colors',
                'hover:bg-muted/60',
            )}
        >
            {/*
                Two targets in one row: the name opens it — a PDF is worth
                looking at before saving — and the button on the right saves it
                without opening anything.
            */}
            <a
                href={attachment.url}
                target="_blank"
                rel="noreferrer"
                className="flex min-w-0 flex-1 items-center gap-3"
            >
                <span className="flex size-9 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                    <FileText className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">
                        {attachment.name}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {readableSize(attachment.size)}
                    </span>
                </span>
            </a>
            <a
                href={downloadUrl(attachment)}
                download={attachment.name}
                title={`${attachment.name} downloaden`}
                aria-label={`${attachment.name} downloaden`}
                className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <Download className="size-4" />
            </a>
        </div>
    );
}

/**
 * What was sent along with a message.
 *
 * Images and video render in place because that is what they are for; a
 * document is a row you click. Nothing plays on its own — a channel where
 * scrolling past starts three videos is a channel nobody scrolls.
 */
export function MessageAttachments({
    attachments,
    onRemove,
}: {
    attachments: MessageAttachment[];
    /**
     * Taking one back. Absent where the reader may not — the same rule as
     * deleting the message, so the button never appears where the endpoint
     * would refuse.
     */
    onRemove?: (attachment: MessageAttachment) => void;
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <ul className="mt-1.5 flex flex-col gap-2">
            {attachments.map((attachment) => (
                <li key={attachment.id} className="group/attachment max-w-lg">
                    {onRemove && (
                        <div className="mb-1 flex justify-end opacity-0 transition-opacity group-hover/attachment:opacity-100 focus-within:opacity-100">
                            <button
                                type="button"
                                onClick={() => onRemove(attachment)}
                                aria-label={`${attachment.name} verwijderen`}
                                title="Bestand verwijderen"
                                className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            >
                                <Trash2 className="size-3.5" />
                            </button>
                        </div>
                    )}
                    {isImage(attachment) ? (
                        <ImageAttachment attachment={attachment} />
                    ) : isVideo(attachment) ? (
                        <div className="relative">
                            <video
                                src={attachment.url}
                                controls
                                preload="metadata"
                                className="max-h-80 w-full rounded-lg border bg-black"
                            />
                            <DownloadOverlay attachment={attachment} />
                        </div>
                    ) : isAudio(attachment) ? (
                        <div className="flex items-center gap-2">
                            <audio
                                src={attachment.url}
                                controls
                                preload="metadata"
                                className="min-w-0 flex-1"
                            />
                            <a
                                href={downloadUrl(attachment)}
                                download={attachment.name}
                                title={`${attachment.name} downloaden`}
                                aria-label={`${attachment.name} downloaden`}
                                className="shrink-0 rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <Download className="size-4" />
                            </a>
                        </div>
                    ) : (
                        <FileAttachment attachment={attachment} />
                    )}
                </li>
            ))}
        </ul>
    );
}
