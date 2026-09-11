import { describe, expect, it } from 'vitest';

import type { ChatMessage } from '@/types/chat';
import {
    canEditMessage,
    lastEditableMessage,
    opensLastMessage,
} from './message-editing';

const ME = 7;
const SOMEBODY_ELSE = 8;

/** Only the fields these rules look at; the rest is noise for this test. */
function message(
    id: string,
    overrides: Omit<Partial<ChatMessage>, 'author'> & {
        author?: Partial<ChatMessage['author']>;
    } = {},
): ChatMessage {
    const { author, ...rest } = overrides;

    return {
        id,
        body: id,
        deletedAt: null,
        author: {
            id: ME,
            name: 'Ik',
            avatarUrl: null,
            isBot: false,
            isGuest: false,
            ...author,
        },
        ...rest,
    } as ChatMessage;
}

function press(
    key: string,
    modifiers: Partial<{
        metaKey: boolean;
        ctrlKey: boolean;
        altKey: boolean;
        shiftKey: boolean;
    }> = {},
) {
    return {
        key,
        metaKey: false,
        ctrlKey: false,
        altKey: false,
        shiftKey: false,
        ...modifiers,
    };
}

const EMPTY = { body: '', files: [], quoting: false };

describe('canEditMessage', () => {
    it('allows your own message', () => {
        expect(canEditMessage(message('a'), ME)).toBe(true);
    });

    it('refuses somebody else their words', () => {
        expect(
            canEditMessage(message('a', { author: { id: SOMEBODY_ELSE } }), ME),
        ).toBe(false);
    });

    it('refuses a tombstone', () => {
        expect(
            canEditMessage(
                message('a', { deletedAt: '2026-09-11T10:00:00Z' }),
                ME,
            ),
        ).toBe(false);
    });

    it('refuses a message still on its way to the server', () => {
        expect(canEditMessage(message('a', { pending: true }), ME)).toBe(false);
    });

    /*
     * A webhook has no author id, so nothing may come out equal to the reader
     * here — not even when the reader's own id is somehow null-ish.
     */
    it('refuses a bot message', () => {
        expect(
            canEditMessage(
                message('a', { author: { id: null, isBot: true } }),
                ME,
            ),
        ).toBe(false);
    });
});

describe('lastEditableMessage', () => {
    it('finds nothing in an empty conversation', () => {
        expect(lastEditableMessage([], ME)).toBeNull();
    });

    it('takes your most recent message', () => {
        const messages = [message('first'), message('second')];

        expect(lastEditableMessage(messages, ME)?.id).toBe('second');
    });

    it('looks past what somebody else said after you', () => {
        const messages = [
            message('mine'),
            message('theirs', { author: { id: SOMEBODY_ELSE } }),
        ];

        expect(lastEditableMessage(messages, ME)?.id).toBe('mine');
    });

    it('looks past your own deleted and pending messages', () => {
        const messages = [
            message('mine'),
            message('gone', { deletedAt: '2026-09-11T10:00:00Z' }),
            message('sending', { pending: true }),
        ];

        expect(lastEditableMessage(messages, ME)?.id).toBe('mine');
    });

    it('finds nothing when you have said nothing here', () => {
        const messages = [message('theirs', { author: { id: SOMEBODY_ELSE } })];

        expect(lastEditableMessage(messages, ME)).toBeNull();
    });
});

describe('opensLastMessage', () => {
    it('takes the arrow on an empty field', () => {
        expect(opensLastMessage(press('ArrowUp'), EMPTY)).toBe(true);
    });

    it('leaves other keys alone', () => {
        expect(opensLastMessage(press('ArrowDown'), EMPTY)).toBe(false);
        expect(opensLastMessage(press('a'), EMPTY)).toBe(false);
    });

    // In a message of several lines the arrow moves the caret, as ever.
    it('leaves the arrow alone once something is typed', () => {
        expect(
            opensLastMessage(press('ArrowUp'), { ...EMPTY, body: 'hoi' }),
        ).toBe(false);
    });

    it('leaves the arrow alone with a file waiting to be sent', () => {
        expect(
            opensLastMessage(press('ArrowUp'), {
                ...EMPTY,
                files: [{ name: 'plaatje.png' }],
            }),
        ).toBe(false);
    });

    it('leaves the arrow alone while a quote stands above the field', () => {
        expect(
            opensLastMessage(press('ArrowUp'), { ...EMPTY, quoting: true }),
        ).toBe(false);
    });

    it.each(['metaKey', 'ctrlKey', 'altKey', 'shiftKey'])(
        'leaves %s combinations to the browser',
        (modifier) => {
            expect(
                opensLastMessage(press('ArrowUp', { [modifier]: true }), EMPTY),
            ).toBe(false);
        },
    );
});
