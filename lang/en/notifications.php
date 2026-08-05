<?php

return [
    'greeting' => 'Hi :name,',

    'activity' => [
        'subject_mentions' => '{1}Somebody mentioned you in :workspace|[2,*]Mentioned :count times in :workspace',
        'subject_unread' => '{1}One new message in :workspace|[2,*]:count new messages in :workspace',
        'intro' => 'There was talk in :workspace while you were away.',
        'messages' => '{1}:count message|[2,*]:count messages',
        'mentions' => 'mentioned :countx',
        'open' => 'Open',
        'open_in_app' => 'Open in Postduif',
        'preferences' => 'You can set how often you get this under [Notifications](:url).',
    ],

    'tickets' => [
        'subject' => '{1}A ticket is going unanswered|[2,*]:count tickets are going unanswered',
        'intro' => 'These tickets are going unanswered:',
        'overdue' => 'past its due date',
        'unanswered' => 'no reply yet',
        'open' => 'Open',
    ],

    'transfer' => [
        'subject' => ':sender is sending you :what',
        'files' => 'files',
    ],

    'invitation' => [
        'subject' => ':inviter is inviting you to :workspace',
    ],
];
