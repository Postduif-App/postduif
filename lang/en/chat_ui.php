<?php

/*
 * The English half of lang/nl/chat_ui.php. Same keys, same order, and a test
 * that goes red the moment one of them has no counterpart here.
 */

return [
    'guest' => [
        'label' => 'Guest',
        'hint' => 'Somebody from outside, only in the channels they were invited to',
    ],

    'join' => [
        'lead' => 'You are reading along in',
        'tail' => '. Join to be able to reply.',
        'submit' => 'Join',
    ],

    'feed' => [
        'empty' => 'Nothing posted yet.',
        'reply' => 'Reply',
        'replies' => '{1}1 reply|[2,*]:count replies',
    ],

    'thread' => [
        'heading' => 'Thread',
        'close' => 'Close thread',
        'replies_closed' => 'Replying is switched off in this channel',
        'join_first' => 'Join this channel to reply',
    ],

    'reactions' => [
        'pick' => 'React with an emoji',
        'search' => 'Find another emoji',
        'dialog_title' => 'Pick an emoji',
        'dialog_description' => 'Find an emoji',
        'placeholder' => 'Find an emoji…',
        'none' => 'No emoji found.',
        'custom' => 'From this workspace',
    ],

    'code' => [
        'copy' => 'Copy',
        'copied' => 'Copied',
    ],

    'members' => [
        'heading' => 'Members',
        'online' => 'Online now',
        'you' => 'you',
    ],

    'mute' => [
        'action' => 'Mute notifications',
        'until' => 'Muted until :moment',
        'until_forever' => 'Muted until you switch it back on',
        'unmute' => 'Switch notifications back on',
        'heading' => 'Keep this channel quiet',

        'duration' => [
            'hour' => 'An hour',
            'workday' => 'The rest of the working day',
            'tomorrow' => 'Until tomorrow',
            'week' => 'A week',
            'forever' => 'Until I switch it back on',
        ],
    ],

    'tags' => [
        'label' => 'Tags',
        'placeholder' => 'Type a tag and press Enter',
        'remove' => 'Remove :tag',
        'in_use' => 'Already in use:',
        'hint' => 'Tags belong to the workspace, not to this channel: the same tag can hang on several channels. A tag that is left on nothing disappears by itself.',
    ],

    'links' => [
        'explanation' => 'Appear in a bar above the conversation, for everybody who can see the channel — guests included.',
        'empty' => 'No buttons yet. Add one below.',
        'new' => 'New button',
        'address' => 'Address',
        'add' => 'Add button',
        'move_up' => 'Move :label forward',
        'move_down' => 'Move :label back',
        'open' => 'Open',
        'open_named' => 'Open :label',
        'remove' => 'Remove :label',
    ],

    'payload' => [
        'too_large' => 'The last message was too big to keep.',
        'show' => 'Show what came in last',
        'hide' => 'Hide what came in last',
        'use' => 'Use :path',
    ],

    'poll' => [
        'closed' => 'Closed',
        'expired' => 'Expired',
        'no_votes' => 'Nobody has voted yet',
        'votes' => '{1}1 person has voted|[2,*]:count people have voted',
        'multiple' => 'several answers allowed',
        'state_closed' => 'closed',
        'state_expired' => 'expired',
        'public_note' => 'everybody sees what you vote',
        'reopen' => 'Reopen poll',
        'close' => 'Close poll',
    ],

    'secret' => [
        'filled' => ':done of :total filled in',
        'complete' => 'complete',
        'until' => 'until :date',
        'expired' => 'expired',
        'revoked' => 'withdrawn',
    ],

    'sent_secret' => [
        'for' => 'For :name',
        'revealed' => 'picked up',
        'expired' => 'no longer available',
        'expires' => 'expires :date',
        'withdraw' => 'Withdraw',
        'withdraw_confirm' => 'Withdraw this secret?',
    ],

    'board' => [
        'editing' => 'Change this notice',
        'pin' => 'Pin up',
        'unpin' => 'Unpin',
        'edit' => 'Change',
        'delete' => 'Take down',
        'delete_confirm' => 'Take this notice off the board?',
        'back' => 'Back to the board',
        'fullscreen' => 'Full screen',
        'close' => 'Close',
        'edited' => 'changed',
        'author_gone' => 'Former colleague',
        'no_comments' => 'No replies yet',
        'comments' => '{1}1 reply|[2,*]:count replies',
        'comment_field' => 'Reply',
        'comment_edit' => 'Change reply',
        'comment_delete' => 'Remove reply',
        'comment_placeholder' => 'Reply to this notice…',
    ],

    'tickets' => [
        'none_outstanding' => 'Nothing outstanding any more.',
        'none_with_status' => 'No tickets with this status.',
    ],
];
