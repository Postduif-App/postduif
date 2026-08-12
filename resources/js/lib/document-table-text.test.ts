import { describe, expect, it } from 'vitest';

import { tableToHtml } from '@/lib/document-table-text';

/** A table in the shape @yoopta/table stores one. */
function table(rows: string[][], withHeaderRow = false) {
    return {
        type: 'table',
        children: rows.map((cells, rowIndex) => ({
            type: 'table-row',
            children: cells.map((text) => ({
                type: 'table-data-cell',
                props: { asHeader: withHeaderRow && rowIndex === 0 },
                children: [{ text }],
            })),
        })),
    };
}

describe('tableToHtml', () => {
    it('keeps every word in the table', () => {
        const html = tableToHtml(
            table([
                ['Artikel', 'Prijs'],
                ['Onderhoud', '95 euro'],
            ]),
        );

        // The point of the whole exercise: these words have to reach body_text,
        // or a price list in a document cannot be searched for.
        expect(html).toContain('Artikel');
        expect(html).toContain('Onderhoud');
        expect(html).toContain('95 euro');
    });

    it('marks a header row as header cells', () => {
        const html = tableToHtml(table([['Artikel'], ['Onderhoud']], true));

        expect(html).toContain('<th>Artikel</th>');
        expect(html).toContain('<td>Onderhoud</td>');
    });

    it('escapes what somebody typed into a cell', () => {
        /*
         * Not a formality. getPlainText() assigns this string to an innerHTML,
         * and an <img> with a handler on it fires the moment it is parsed —
         * unlike a <script>, which innerHTML refuses to run. Left unescaped,
         * typing this into a table would be a way to run code.
         */
        const html = tableToHtml(table([['<img src=x onerror=alert(1)>']]));

        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
    });

    it('reads a cell whose text sits deeper than one level', () => {
        const html = tableToHtml({
            type: 'table',
            children: [
                {
                    type: 'table-row',
                    children: [
                        {
                            type: 'table-data-cell',
                            children: [
                                { children: [{ text: 'diep' }] },
                                { text: 'weggestopt' },
                            ],
                        },
                    ],
                },
            ],
        });

        expect(html).toContain('<td>diepweggestopt</td>');
    });

    it('survives an empty table', () => {
        expect(tableToHtml({ type: 'table', children: [] })).toBe(
            '<table><tbody></tbody></table>',
        );
    });
});
