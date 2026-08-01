import { describe, expect, it } from 'vitest';

import { parseInline } from './inline-markdown';
import type { InlineNode } from './inline-markdown';

/**
 * A node tree, flattened to something readable in a failure message.
 *
 * Comparing whole trees would put a wall of nested objects in every diff; this
 * turns `**vet**` into `strong("vet")`, which says the same thing in one line.
 */
function shape(nodes: InlineNode[]): string {
    return nodes
        .map((node) =>
            node.type === 'text'
                ? JSON.stringify(node.value)
                : `${node.type}(${shape(node.children)})`,
        )
        .join('+');
}

describe('parseInline', () => {
    it.each([
        ['**vet**', 'strong("vet")'],
        ['__ook vet__', 'strong("ook vet")'],
        ['*cursief*', 'em("cursief")'],
        ['_ook cursief_', 'em("ook cursief")'],
        ['~~weg~~', 'strike("weg")'],
    ])('reads %s as %s', (input, expected) => {
        expect(shape(parseInline(input))).toBe(expected);
    });

    it('keeps the text around a marked phrase', () => {
        expect(shape(parseInline('hallo **daar** jij'))).toBe(
            '"hallo "+strong("daar")+" jij"',
        );
    });

    it('reads two asterisks as bold rather than italic wrapping italic', () => {
        expect(shape(parseInline('**vet**'))).not.toContain('em(');
    });

    it('nests one inside the other', () => {
        expect(shape(parseInline('**vet met _cursief_ erin**'))).toBe(
            'strong("vet met "+em("cursief")+" erin")',
        );
    });

    /*
     * The cases below are the whole reason this parser has lookarounds. Each one
     * is text somebody types in a work chat every day, and each one would come
     * out mangled by a naive pair-matching pass.
     */
    it.each([
        ['snake_case_naam blijft heel', 'een identifier'],
        ['@jan_de_vries hoi', 'een handle met underscores'],
        ['2 * 3 * 4 is twaalf', 'vermenigvuldigen'],
        ['sterretje ** alleen', 'een marker zonder inhoud'],
        ['een_ enkele _underscore', 'markers die aan spaties grenzen'],
    ])('leaves %s alone (%s)', (input) => {
        expect(shape(parseInline(input))).toBe(JSON.stringify(input));
    });

    it('stops an unclosed marker at the end of its line', () => {
        const input = 'niet afgesloten *hier\nen de volgende regel';

        expect(shape(parseInline(input))).toBe(JSON.stringify(input));
    });

    it('marks each line of a multi-line message on its own', () => {
        expect(shape(parseInline('*een*\n*twee*'))).toBe(
            'em("een")+"\\n"+em("twee")',
        );
    });

    it('leaves a mention inside a marked phrase for the next pass to find', () => {
        // MessageBody resolves references in the text nodes this produces, so
        // the handle has to survive the formatting pass as plain text.
        expect(shape(parseInline('**hoi @fenna**'))).toBe(
            'strong("hoi @fenna")',
        );
    });

    it('gives back a single text node when there is nothing to mark', () => {
        expect(parseInline('gewoon een zin')).toEqual([
            { type: 'text', value: 'gewoon een zin' },
        ]);
    });

    it('gives back nothing for an empty message', () => {
        expect(parseInline('')).toEqual([]);
    });

    it('stops nesting rather than recursing without end', () => {
        const deep = '*'.repeat(12) + 'diep' + '*'.repeat(12);

        expect(() => parseInline(deep)).not.toThrow();
    });
});
