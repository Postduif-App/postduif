import { describe, expect, it } from 'vitest';

import { splitCodeBlocks } from './code-blocks';
import type { MessageBlock } from './code-blocks';

/**
 * The blocks, flattened to one readable line — the same trick the inline
 * parser's tests use, for the same reason: a diff full of nested objects says
 * less than `text("hoi")+code:php("echo 1;")`.
 */
function shape(blocks: MessageBlock[]): string {
    return blocks
        .map((block) =>
            block.type === 'text'
                ? `text(${JSON.stringify(block.value)})`
                : `code:${block.language ?? '-'}(${JSON.stringify(block.code)})`,
        )
        .join('+');
}

describe('splitCodeBlocks', () => {
    it('reads a fenced block with a language', () => {
        expect(shape(splitCodeBlocks('```php\necho 1;\n```'))).toBe(
            'code:php("echo 1;")',
        );
    });

    it('reads a fence with no language', () => {
        expect(shape(splitCodeBlocks('```\nplat\n```'))).toBe('code:-("plat")');
    });

    /** The message from the bug report, near enough. */
    it('keeps the text around a block', () => {
        const body =
            'Mooie PHP snippet dit:\n```php\n<?php\n\necho "Hoi";\n```\nEn dat was het.';

        expect(shape(splitCodeBlocks(body))).toBe(
            'text("Mooie PHP snippet dit:")+code:php("<?php\\n\\necho \\"Hoi\\";")+text("En dat was het.")',
        );
    });

    it('keeps blank lines inside a block', () => {
        const [block] = splitCodeBlocks('```php\na\n\nb\n```');

        expect(block).toEqual({
            type: 'code',
            language: 'php',
            code: 'a\n\nb',
        });
    });

    it('reads several blocks in one message', () => {
        expect(
            shape(splitCodeBlocks('```js\na\n```\ntussen\n```css\nb\n```')),
        ).toBe('code:js("a")+text("tussen")+code:css("b")');
    });

    it('lowercases the language so PHP and php are one thing', () => {
        expect(shape(splitCodeBlocks('```PHP\na\n```'))).toBe('code:php("a")');
    });

    it('forgives trailing spaces on either fence', () => {
        expect(shape(splitCodeBlocks('```php  \na\n```  '))).toBe(
            'code:php("a")',
        );
    });

    it('takes an empty block', () => {
        expect(shape(splitCodeBlocks('```\n```'))).toBe('code:-("")');
    });

    /*
     * The cases below stay text. Each is a message somebody types without
     * meaning to open a block, and each would come out mangled by a pass that
     * merely looked for three backticks.
     */
    it('leaves an unclosed fence as text', () => {
        const body = 'kijk:\n```php\necho 1;';

        expect(shape(splitCodeBlocks(body))).toBe(
            `text(${JSON.stringify(body)})`,
        );
    });

    it('does not open a block from the middle of a line', () => {
        const body = 'typ ```php om te beginnen';

        expect(shape(splitCodeBlocks(body))).toBe(
            `text(${JSON.stringify(body)})`,
        );
    });

    it('does not let a closing fence end mid-sentence', () => {
        const body = '```php\na\n``` en toen';

        expect(shape(splitCodeBlocks(body))).toBe(
            `text(${JSON.stringify(body)})`,
        );
    });

    it('gives back nothing for an empty message', () => {
        expect(splitCodeBlocks('')).toEqual([]);
    });

    it('gives back one text block when there is no code', () => {
        expect(splitCodeBlocks('gewoon een zin')).toEqual([
            { type: 'text', value: 'gewoon een zin' },
        ]);
    });
});
