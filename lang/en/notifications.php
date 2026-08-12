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

    'contract' => [
        'subject' => ':sender is asking you to sign :what',
        'subject_signed' => ':name has signed :title',
        'subject_declined' => ':name will not sign :title',
        'subject_completed' => ':title is complete',
        'body_signed' => '{0}:name has signed ":title".|{1}:name has signed ":title". One person still has to sign.|[2,*]:name has signed ":title". :count people still have to sign.',
        'body_declined' => ':name has let you know they will not sign ":title".',
        'body_completed' => 'Everybody who was asked has responded to ":title".',
        'tally' => ':signed of the :total people asked have signed.',
        'download' => 'Download the signed copy',
        'no_copy_yet' => 'The signed copy could not be composed yet. The signatures are safe; you can try the download again later from the overview.',
    ],

    'invitation' => [
        'subject' => ':inviter is inviting you to :workspace',
    ],
];
