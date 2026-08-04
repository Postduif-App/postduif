/** The flattened lines for one language, keyed "domain.path.to.key". */
export type TranslationLines = Record<string, string>;

/** What a :placeholder may be replaced with. */
export type Replacements = Record<string, string | number>;

/**
 * One line, with its placeholders filled in.
 *
 * A pure function beside the hook that uses it, so the awkward parts — a
 * missing key, a placeholder nobody passed, a count that decides the wording —
 * can be tested without rendering anything.
 *
 * A missing key returns the key. That is deliberately ugly: an empty string
 * would leave a hole nobody notices in review, where "chat.not_a_member" on
 * screen is impossible to miss and says exactly what is missing.
 */
export function translate(
    lines: TranslationLines,
    key: string,
    replacements: Replacements = {},
): string {
    const line = lines[key];

    if (line === undefined) {
        return key;
    }

    return fill(line, replacements);
}

/**
 * The branch of a pluralised line that fits this count.
 *
 * Mirrors Laravel's own format so both halves of the application read the same
 * files: "{1}one thing|[2,*]:count things". Explicit ranges rather than the
 * shorter one|many, because "Eén bericht" is not "1 bericht" and only the
 * spelled-out branch can say so.
 */
export function choose(
    lines: TranslationLines,
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const line = lines[key];

    if (line === undefined) {
        return key;
    }

    const branches = line.split('|');

    for (const branch of branches) {
        const exact = branch.match(/^\{(\d+)\}(.*)$/s);

        if (exact && Number(exact[1]) === count) {
            return fill(exact[2], { count, ...replacements });
        }

        const range = branch.match(/^\[(\d+),(\d+|\*)\](.*)$/s);

        if (range) {
            const from = Number(range[1]);
            const to = range[2] === '*' ? Infinity : Number(range[2]);

            if (count >= from && count <= to) {
                return fill(range[3], { count, ...replacements });
            }
        }
    }

    /*
     * No branch claimed this count. Falling back to the last one rather than to
     * the key: a line that forgot [2,*] is a wording bug, and showing the
     * plural is a far better wrong answer than showing "chat.messages".
     */
    const last = branches[branches.length - 1] ?? key;

    return fill(last.replace(/^(\{\d+\}|\[\d+,(?:\d+|\*)\])/, ''), {
        count,
        ...replacements,
    });
}

function fill(line: string, replacements: Replacements): string {
    return Object.entries(replacements).reduce(
        (filled, [name, value]) => filled.replaceAll(`:${name}`, String(value)),
        line,
    );
}
