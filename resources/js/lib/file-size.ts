/**
 * Bytes as somebody reads them: "1,4 MB" here, "1.4 MB" on an English page.
 *
 * One function rather than the four near-copies this replaces. They disagreed
 * about the largest unit and about where the decimal went, which nobody had
 * decided — they were simply written at different times.
 *
 * The units stay as they are in every language; only the number moves, so the
 * formatter comes in as an argument. A hook cannot be called from a plain
 * function, and this one is called from render bodies as well as from other
 * helpers — see `reactionLabel` in message-list.tsx for the same shape.
 *
 * A decimal only above a megabyte and only under a hundred of them: "1,4 MB"
 * says something, "743,2 kB" and "1.024,3 MB" say the same as their rounded
 * forms with more characters.
 */
const UNITS = ['B', 'kB', 'MB', 'GB', 'TB'];

export function readableSize(bytes: number, number: Intl.NumberFormat): string {
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    const decimals = unit >= 2 && value < 100 ? 1 : 0;

    return `${number.format(Number(value.toFixed(decimals)))} ${UNITS[unit]}`;
}
