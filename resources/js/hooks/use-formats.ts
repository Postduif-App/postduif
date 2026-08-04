import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Dates, times and lists in the reader's language.
 *
 * Intl does the work; all this does is stop the locale being written down
 * twenty-eight times. A formatter built with a fixed 'nl-NL' gives an English
 * reader "maandag 4 augustus" underneath a page that is otherwise in English —
 * the kind of half-translation that reads worse than no translation at all.
 *
 * Named shapes rather than an options argument, and the names are the shapes
 * that were already in use across the app. `formats.dateTime.format(x)` says at
 * the call site what kind of stamp it is; passing options in would move that
 * decision into every component and let one idea drift into four spellings.
 *
 * Memoised on the locale because Intl formatters are not cheap to build and a
 * message list would otherwise build them per row.
 */
export function useFormats() {
    const { locale } = usePage<{ locale: string }>().props;

    return useMemo(
        () => ({
            /** Maandag 4 augustus 2026 — the divider between days in a channel. */
            day: new Intl.DateTimeFormat(locale, { dateStyle: 'full' }),

            /** 14:05 — beside a message. */
            time: new Intl.DateTimeFormat(locale, {
                hour: '2-digit',
                minute: '2-digit',
            }),

            /** 4 augustus — a deadline, where the year is obvious. */
            date: new Intl.DateTimeFormat(locale, {
                day: 'numeric',
                month: 'long',
            }),

            /** Ma 4 aug 14:05 — a moment worth placing in the week. */
            moment: new Intl.DateTimeFormat(locale, {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit',
            }),

            /** Ma 14:05 — near enough that the date does not matter. */
            dayTime: new Intl.DateTimeFormat(locale, {
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit',
            }),

            /** 04-08-2026 — a stamp in a table, where space is short. */
            shortDate: new Intl.DateTimeFormat(locale, { dateStyle: 'short' }),

            /** 4 aug 2026 — a date in a row, where the time adds nothing. */
            mediumDate: new Intl.DateTimeFormat(locale, {
                dateStyle: 'medium',
            }),

            /** 4 augustus 2026 — a date read as a sentence. */
            longDate: new Intl.DateTimeFormat(locale, { dateStyle: 'long' }),

            /** 04-08-2026, 14:05 — a stamp in a dense list. */
            shortDateTime: new Intl.DateTimeFormat(locale, {
                dateStyle: 'short',
                timeStyle: 'short',
            }),

            /** 4 aug 2026, 14:05 */
            dateTime: new Intl.DateTimeFormat(locale, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }),

            /** 4 augustus 2026 om 14:05 — a heading over a post. */
            longDateTime: new Intl.DateTimeFormat(locale, {
                dateStyle: 'long',
                timeStyle: 'short',
            }),

            /**
             * 1,4 — a file size, and anything else where one decimal is
             * plenty. The decimal separator is the point: a Dutch reader
             * expects "1,4 MB" and an English one "1.4 MB", and hard-coding
             * either gets it wrong for half the workspace.
             */
            number: new Intl.NumberFormat(locale, {
                maximumFractionDigits: 1,
            }),

            /** "Jij, Anna en 3 anderen" */
            names: new Intl.ListFormat(locale, { type: 'conjunction' }),
        }),
        [locale],
    );
}
