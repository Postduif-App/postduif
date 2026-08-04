<?php

/*
 * The English half of lang/nl/panelen.php. Same keys, same order, and a test
 * that goes red the moment one of them has no counterpart here.
 */

return [
    'save' => 'Save',
    'cancel' => 'Cancel',

    'tickets' => [
        'title' => 'Tickets',
        'intro' => 'Everything from the channels you can see',
        'new' => 'New ticket',
        'outstanding' => 'Outstanding',
        'everything' => 'Everything',
        'priority_filter' => 'Priority',
        'channel_filter' => 'Channel',
        'any' => ':label: all',
        'no_channels' => 'No channel keeps tickets yet. Switch tickets on in a channel\'s settings.',
        'none' => 'No tickets match this.',
        'open_in' => 'Open #:number in #:channel',
        'open_in_its_channel' => 'Open #:number in its channel',
    ],

    'ticket' => [
        'unknown' => 'Unknown',
        'close' => 'Close ticket',
        'title_field' => 'Title',
        'edit_title' => 'Change the title',
        'body_field' => 'Description',
        'edit_body' => 'Change the description',
        'escape_cancels' => 'cancels',
        'status' => 'Status',
        'priority' => 'Priority',
        'assignee' => 'Assigned to',
        'nobody' => 'Nobody',
        'solved' => 'This is resolved',
        'not_solved' => 'Not resolved after all',
        'from_message' => 'From a message by :author',
        'source_deleted' => 'That message has been deleted',
        'guest' => 'guest',
        'edited' => 'edited',
        'comment_withdrawn' => 'This reply was withdrawn',
        'comment_placeholder' => 'Reply to this ticket',

        'event' => [
            'system' => 'System',
            'created' => ':who opened this ticket',
            'status_changed' => ':who set the status to :status',
            'priority_changed' => ':who set the priority to :priority',
            'assigned' => ':who assigned this ticket',
            'unassigned' => ':who took the assignment off',
            'due_date_set' => ':who set a due date',
            'due_date_cleared' => ':who took the due date off',
            'other' => ':who changed something',
        ],
    ],

    'scheduled' => [
        'title' => 'Scheduled',
        'waiting' => '{1}1 message is still waiting|[2,*]:count messages are still waiting',
        'close' => 'Close',
        'empty' => 'Nothing is queued up for this channel.',
        'failed' => 'This message could not be sent.',
        'body_field' => 'Message',
        'send_at_field' => 'Send at',
        'withdraw' => 'Withdraw',
        'confirm_title' => 'Withdraw this scheduled message?',
        'confirm_body' => 'It will not go out, and the text is gone. Nobody has seen it anywhere, so nothing is left of it.',
    ],

    'section' => [
        'file' => 'Put in a group',
        'filed_in' => 'In the group :name',
        'yours_alone' => 'Your groups — only you see them',
        'new_menu' => 'New group…',
        'new' => 'New group',
        'intro' => 'A heading in your own sidebar. Your colleagues see nothing of it — unlike a tag on the channel, which does count for everyone.',
        'name_field' => 'Name',
        'name_placeholder' => 'For example: Customers',
        'create' => 'Create group',
    ],

    'pinned' => [
        'title' => 'Pinned',
        'by' => 'Pinned by :who · :moment',
        'at' => 'Pinned on :moment',
        'count' => '{1}1 pinned message|[2,*]:count pinned messages',
        'view' => 'View',
        'messages' => '{1}1 message|[2,*]:count messages',
        'close' => 'Close pinned messages',
        'empty' => 'Nothing is pinned in this channel.',
        'jump' => 'Go to message',
        'unpin' => 'Unpin',
        'unreachable' => 'This message sits outside the loaded part of the channel. Scroll up to fetch it.',
    ],

    'status' => [
        'title' => 'Your status',
        'intro' => 'What you are up to, and whether you can be disturbed.',
        'field' => 'Status',
        'emoji_field' => 'Emoji',
        'placeholder' => 'What are you working on?',
        'availability' => 'Availability',
        'clear' => 'Clear status',

        'suggestion' => [
            'meeting' => 'In a meeting',
            'lunch' => 'Lunch break',
            'focus' => 'Heads down',
            'home' => 'Working from home',
            'commuting' => 'On the road',
            'sick' => 'Off sick',
            'holiday' => 'On holiday',
        ],
    ],
];
