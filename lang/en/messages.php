<?php

return [
    'deleted' => 'This message was deleted',
    'edited' => 'edited',
    'bot' => 'Bot',
    'forwarded_from' => 'Forwarded — originally from',
    'empty' => 'No messages yet. Start the conversation.',

    'pinned' => 'Pinned',
    'pinned_by' => 'Pinned by :name',

    'reaction' => '{1}:names reacted with :emoji|[2,*]:names reacted with :emoji',
    'reaction_you' => 'You',
    'reaction_someone' => 'somebody else',
    'reaction_others' => ':count others',

    /*
     * Reminders on a message. The choices are deliberately coarse: somebody who
     * wants an exact time schedules a message. This is for "I will come back to
     * this in a bit".
     */
    'reminder' => [
        'heading' => 'Remind me',
        'when' => [
            '20m' => 'In 20 minutes',
            '1h' => 'In an hour',
            '3h' => 'In three hours',
            'tomorrow' => 'Tomorrow morning',
            'next_week' => 'Monday next week',
        ],
    ],

    'actions' => [
        'copy_text' => 'Copy text',
        'copy_link' => 'Copy a link to this message',
        'quote' => 'Quote',
        'quote_key' => 'Quote (R)',
        'ticket' => 'Make a ticket of this message',
        'forward' => 'Forward to another channel',
        'remind' => 'Remind me about this',
        'save' => 'Save for later',
        'unsave' => 'Stop saving',
        'pin' => 'Pin in this channel',
        'unpin' => 'Unpin',
        'edit' => 'Edit message',
        'edit_key' => 'Edit message (E)',
        'reply' => 'Reply in thread',
        'reply_key' => 'Reply in thread (T)',
        'delete' => 'Delete message',
        'delete_key' => 'Delete message (D)',
    ],

    'editor' => [
        'save' => 'Save',
        'cancel' => 'Cancel',
        'escape_hint' => 'cancels',
    ],

    'delete' => [
        'question' => 'Delete this message?',
        'with_replies' => 'The replies in the thread stay; this spot will say "This message was deleted".',
        'for_everyone' => 'The message disappears for everybody in this channel. You cannot undo this.',
        'cancel' => 'Cancel',
        'confirm' => 'Delete',
    ],
];
