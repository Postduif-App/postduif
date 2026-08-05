/**
 * What a choice says: a line naming it, and a line explaining it.
 *
 * Written out by hand in every list of options this application has, which is
 * how the two lines ended up touching in most of them and spaced in one: the
 * gap was somebody remembering, rather than something the shape provided. The
 * title and the hint belong together, so they are one thing.
 *
 * @param subtle Drop the title to normal weight. For a list long enough that a
 *   bold line per row becomes the texture of the page rather than its emphasis
 *   — the ability list on the roles screen is the one that qualifies.
 */
export function ChoiceText({
    title,
    hint,
    subtle = false,
}: {
    title: string;
    hint?: string;
    subtle?: boolean;
}) {
    return (
        <span className="min-w-0">
            <span className={subtle ? 'block' : 'block font-medium'}>
                {title}
            </span>

            {hint && (
                // Roomier than the default for its size: these hints run to
                // several lines, and at text-xs the stock leading packs them
                // tight enough to read as a block rather than as sentences.
                <span className="mt-1 block text-xs leading-relaxed text-muted-foreground">
                    {hint}
                </span>
            )}
        </span>
    );
}
