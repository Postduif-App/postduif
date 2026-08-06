import { describe, expect, it } from 'vitest';

import { commandIn } from '@/components/chat/composer';
import type { ComposerCommand } from '@/components/chat/composer';

const COMMANDS: ComposerCommand[] = [
    { name: 'poll', description: '', run: () => {} },
    { name: 'storing', description: '', run: () => {}, takesArguments: true },
];

describe('a line that is a command', () => {
    it('recognises the command on its own', () => {
        expect(commandIn('/poll', COMMANDS)?.command.name).toBe('poll');
        expect(commandIn('/poll', COMMANDS)?.args).toBe('');
    });

    it('hands over everything after the name, as one string', () => {
        const typed = commandIn('/storing de printer doet het niet', COMMANDS);

        expect(typed?.command.name).toBe('storing');
        expect(typed?.args).toBe('de printer doet het niet');
    });

    /** A name that merely starts with a command is a different word. */
    it('does not match a longer name', () => {
        expect(commandIn('/storingen', COMMANDS)).toBeNull();
    });

    it('leaves an ordinary message alone', () => {
        expect(commandIn('en/of', COMMANDS)).toBeNull();
        expect(commandIn('kijk eens op /storing', COMMANDS)).toBeNull();
        expect(commandIn('/onbekend', COMMANDS)).toBeNull();
    });

    it('reads a command however it was capitalised', () => {
        expect(commandIn('/Storing nu', COMMANDS)?.command.name).toBe(
            'storing',
        );
    });

    it('survives the extra spaces of somebody typing quickly', () => {
        expect(commandIn('/storing   printer   stuk', COMMANDS)?.args).toBe(
            'printer   stuk',
        );
    });
});
