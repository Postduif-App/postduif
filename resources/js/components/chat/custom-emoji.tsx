import { useCustomEmoji } from '@/hooks/use-custom-emoji';
import type { CustomEmojiEntry } from '@/lib/custom-emoji';
import { wholeCustomEmoji } from '@/lib/custom-emoji';
import { cn } from '@/lib/utils';

/**
 * One of a workspace's own emoji, drawn where a symbol would be.
 *
 * Sized in em rather than in pixels, so it grows with whatever it sits in: the
 * same component draws it at body size inside a sentence, at pill size in a
 * reaction, and large in a message that is nothing else. A fixed size would
 * make it the one thing on the page that ignores the reader's font settings.
 *
 * The alt text is the shortcode somebody typed. Read aloud it says the name,
 * which is what the author meant; and if the picture fails to load, the text
 * that appears is the text the message was written with.
 */
export function CustomEmoji({
    entry,
    className,
}: {
    entry: CustomEmojiEntry;
    className?: string;
}) {
    return (
        <img
            src={entry.url}
            alt={`:${entry.name}:`}
            title={`:${entry.name}:`}
            // Lazily, because a channel scrolled back through holds hundreds of
            // these and the ones above the fold are the only ones anybody is
            // looking at.
            loading="lazy"
            decoding="async"
            draggable={false}
            className={cn(
                'inline-block h-[1.4em] w-auto max-w-[1.4em] object-contain align-text-bottom select-none',
                className,
            )}
        />
    );
}

/**
 * One stored reaction, drawn as whatever it turns out to be.
 *
 * A reaction is one string in one column — "👍" or ":shipit:" — and the pills
 * that show them sit in two different components. This is the one place that
 * decides which of the two it is looking at, so a message pill and a prikbord
 * pill cannot start disagreeing.
 *
 * A shortcode with no picture behind it, because the emoji was deleted after
 * somebody reacted with it, falls back to its own text. The pill keeps working
 * — you can still click it off — and reads as the name it was.
 */
export function ReactionEmoji({ emoji }: { emoji: string }) {
    const { byName } = useCustomEmoji();

    const entry = wholeCustomEmoji(emoji, byName);

    return entry === null ? (
        <span>{emoji}</span>
    ) : (
        <CustomEmoji entry={entry} />
    );
}
