import type { ChatMessage } from '@/types/chat';

/**
 * Whether this reader may rewrite this message.
 *
 * Your own words are yours, and only while they are still words: a tombstone
 * has nothing left to rewrite, and a message still on its way to the server
 * has no id to send the edit to yet. A bot message is nobody's own words —
 * a channel manager may take one down, but nobody may put words in its mouth.
 *
 * Its own function rather than a condition inside the message row, because the
 * composer has to ask the same question from the other side: which message does
 * the arrow key open? Two copies of this rule would drift, and the way they
 * would drift is that the arrow opens a row whose editor refuses to appear.
 */
export function canEditMessage(
    message: ChatMessage,
    currentUserId: number,
): boolean {
    return (
        message.deletedAt === null &&
        !message.pending &&
        !message.author.isBot &&
        message.author.id === currentUserId
    );
}

/**
 * The most recent message in this conversation that this reader may rewrite,
 * or null when there is none.
 *
 * Searched from the end, because "the last thing I said" is what the arrow key
 * promises — not the last thing said in the channel. Somebody else answering in
 * between must not take the edit away from you.
 */
export function lastEditableMessage(
    messages: ChatMessage[],
    currentUserId: number,
): ChatMessage | null {
    for (let index = messages.length - 1; index >= 0; index--) {
        const message = messages[index];

        if (canEditMessage(message, currentUserId)) {
            return message;
        }
    }

    return null;
}

/** What stands in the composer at the moment the key is pressed. */
interface ComposerState {
    body: string;
    /** Files picked but not yet sent. */
    files: unknown[];
    /** Whether a message is being answered, shown above the field. */
    quoting: boolean;
}

/**
 * Whether this keystroke hands the field over to editing your last message.
 *
 * Only on a genuinely empty composer. In a message of several lines the arrow
 * is how you get to the line above, and with a file picked or a quote standing
 * there the field is not empty at all — jumping into an old message from under
 * either of those throws away work somebody was halfway through.
 *
 * Modifier combinations stay with the browser and the platform: Shift+Up
 * selects, Cmd+Up goes to the top of the page.
 */
export function opensLastMessage(
    event: {
        key: string;
        metaKey: boolean;
        ctrlKey: boolean;
        altKey: boolean;
        shiftKey: boolean;
    },
    state: ComposerState,
): boolean {
    if (event.key !== 'ArrowUp') {
        return false;
    }

    if (event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) {
        return false;
    }

    return state.body === '' && state.files.length === 0 && !state.quoting;
}
