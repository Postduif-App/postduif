<?php

return [
    'attachment-type' => [
        'hint' => [
            'Images' => 'png, jpg, gif, webp — shown in the conversation',
            'Video' => 'mp4, webm, mov — play in the conversation',
            'Audio' => 'mp3, m4a, ogg, wav',
            'Documents' => 'pdf, Word, Excel, PowerPoint, txt, csv',
            'Archives' => 'zip, 7z, tar, gz',
        ],
        'label' => [
            'Images' => 'Images',
            'Video' => 'Video',
            'Audio' => 'Audio',
            'Documents' => 'Documents',
            'Archives' => 'Archives',
        ],
    ],
    'availability' => [
        'description' => [
            'Available' => 'Around as usual.',
            'Away' => 'You are away for a bit; notifications still come through.',
            'DoNotDisturb' => 'No notifications go out. Mentions keep until you are back.',
        ],
        'label' => [
            'Available' => 'Available',
            'Away' => 'Away',
            'DoNotDisturb' => 'Do not disturb',
        ],
    ],
    'channel-document-policy' => [
        'description' => [
            'Disabled' => 'This channel keeps no documents.',
            'Everyone' => 'Everyone in this channel writes along, guests included.',
            'Members' => 'Guests do read the documents, but do not write in them.',
        ],
        'label' => [
            'Disabled' => 'No documents',
            'Everyone' => 'Everyone in this channel',
            'Members' => 'Members only, no guests',
        ],
    ],
    'channel-layout' => [
        'description' => [
            'Chat' => 'Messages one under the other, like an ordinary conversation.',
            'Feed' => 'Longer messages with more room, like a newsletter or a blog.',
        ],
        'getLabel' => [
            'Chat' => 'Conversation',
            'Feed' => 'Feed',
        ],
    ],
    'channel-posting-policy' => [
        'description' => [
            'Everyone' => 'An ordinary conversation: every member can post.',
            'Admins' => 'A broadcast channel: others can still react and reply in threads.',
        ],
        'label' => [
            'Everyone' => 'Everyone in this channel',
            'Admins' => 'Only admins and whoever made the channel',
        ],
    ],
    'channel-ticket-policy' => [
        'description' => [
            'Disabled' => 'This channel is only a conversation.',
            'Everyone' => 'A customer channel: the customer can open tickets themselves.',
            'Members' => 'Guests do read the tickets, but do not open them.',
        ],
        'label' => [
            'Disabled' => 'No tickets',
            'Everyone' => 'Everyone in this channel',
            'Members' => 'Members only, no guests',
        ],
    ],
    'channel-type' => [
        'getLabel' => [
            'Public' => 'Public',
            'Private' => 'Private',
            'Direct' => 'DM',
        ],
    ],
    'contract-status' => [
        'label' => [
            'Draft' => 'Draft',
            'Sent' => 'Sent',
            'Completed' => 'Complete',
            'Cancelled' => 'Withdrawn',
            'Expired' => 'Expired',
        ],
        'description' => [
            'Draft' => 'Not sent yet. The document and the fields can still be changed.',
            'Sent' => 'The links have gone out. Whoever has not signed yet can be reminded.',
            'Completed' => 'Everybody has been round. The signed copy is ready to download.',
            'Cancelled' => 'Stopped by whoever asked. The links no longer work.',
            'Expired' => 'The deadline passed without everybody signing.',
        ],
    ],
    'feature-group' => [
        'description' => [
            'Conversation' => 'What a message can carry, on top of the channels and threads everybody spends the day in.',
            'Work' => 'What is left over, what gets recorded and what gets kept — beside the conversation it came out of.',
            'Outside' => 'For everybody who is not a colleague: a customer, a supplier, somebody who has to sign or collect one thing once.',
            'Automation' => 'What happens without anybody pressing anything, and where other systems may knock.',
        ],
        'label' => [
            'Conversation' => 'The conversation',
            'Work' => 'The work that follows',
            'Outside' => 'People from outside',
            'Automation' => 'Automatic and connected',
        ],
    ],
    'inbox-item-type' => [
        'label' => [
            'Mention' => 'Mentioned',
            'Reply' => 'Reply',
            'ThreadReply' => 'Thread',
            'PollVote' => 'Poll',
            'ContractProgress' => 'Contract',
            'Reminder' => 'Reminder',
        ],
    ],
    'mail-template-kind' => [
        'label' => [
            'ContractRequest' => 'Request to sign',
            'ContractSigned' => 'The signed document',
        ],
        'description' => [
            'ContractRequest' => 'The mail asking somebody to sign a contract. The reminder uses this text too.',
            'ContractSigned' => 'The mail that goes to everybody who signed once it is done, with the signed PDF attached.',
        ],
    ],
    'mail-transport' => [
        'label' => [
            'Default' => 'Through Postduif itself',
            'Smtp' => 'Your own SMTP server',
            'Postmark' => 'Postmark',
            'Lettermint' => 'Lettermint',
        ],
        'description' => [
            'Default' => 'The application\'s mail server. Nothing to set up and nothing to maintain.',
            'Smtp' => 'The details of your own mail server or your hosting party\'s. Works everywhere, and is slowest when that server is far away.',
            'Postmark' => 'A sending service for transactional mail. You need a server token from your Postmark account.',
            'Lettermint' => 'A European sending service. You need a project token from your Lettermint environment.',
        ],
    ],
    'member-panel-visibility' => [
        'label' => [
            'Off' => 'Off',
            'Everyone' => 'Everyone in the workspace',
            'Admins' => 'Only admins and the owner',
        ],
    ],
    'signature-method' => [
        'label' => [
            'Drawn' => 'Drawn',
            'Typed' => 'Typed',
        ],
        'statement' => [
            'Drawn' => 'drawn by hand',
            'Typed' => 'set as a typed name',
        ],
    ],
    'smtp-encryption' => [
        'label' => [
            'StartTls' => 'STARTTLS',
            'Tls' => 'TLS (direct)',
            'None' => 'None',
        ],
        'description' => [
            'StartTls' => 'The connection opens in the clear and is then locked. Usually port 587 — this is what most providers want.',
            'Tls' => 'Encrypted from the first byte. Usually port 465.',
            'None' => 'Unencrypted. Only for a relay on your own network: your password goes over the wire readable.',
        ],
    ],
    'ticket-priority' => [
        'label' => [
            'Low' => 'Low',
            'Normal' => 'Normal',
            'High' => 'High',
            'Urgent' => 'Urgent',
        ],
    ],
    'ticket-status' => [
        'description' => [
            'Open' => 'Came in, nobody has picked it up.',
            'InProgress' => 'Somebody is working on this.',
            'Waiting' => 'The ball is with the customer.',
            'Resolved' => 'Dealt with, waiting to be confirmed.',
            'Closed' => 'Finished for good.',
        ],
        'label' => [
            'Open' => 'Open',
            'InProgress' => 'In progress',
            'Waiting' => 'Waiting on customer',
            'Resolved' => 'Resolved',
            'Closed' => 'Closed',
        ],
    ],
    'transfer-audience' => [
        'description' => [
            'Everyone' => 'Whoever has the link can download. Forwarding it works too.',
            'WorkspaceMembers' => 'The recipient has to sign in and be a member. Forwarded outside, it gets them nowhere.',
            'NamedRecipients' => 'Everybody gets their own link by mail. Forwarding still works, but you see it in the counters and you can withdraw one address without touching the rest.',
        ],
        'label' => [
            'Everyone' => 'Anyone with the link',
            'WorkspaceMembers' => 'Members of this workspace only',
            'NamedRecipients' => 'These email addresses only',
        ],
    ],
    'workflow-branch' => [
        'label' => [
            'Then' => 'If it holds',
            'Else' => 'If not',
        ],
    ],
    'workflow-condition-match' => [
        'label' => [
            'All' => 'all of the rules hold',
            'Any' => 'any of the rules holds',
        ],
    ],
    'workflow-condition-operator' => [
        'label' => [
            'Equals' => 'Is equal to',
            'NotEquals' => 'Is not equal to',
            'Contains' => 'Contains',
            'NotContains' => 'Does not contain',
            'StartsWith' => 'Starts with',
            'EndsWith' => 'Ends with',
            'IsOneOf' => 'Is one of',
            'IsNoneOf' => 'Is none of',
            'GreaterThan' => 'Is greater than',
            'LessThan' => 'Is less than',
            'GreaterOrEqual' => 'Is at least',
            'LessOrEqual' => 'Is at most',
            'Before' => 'Is before',
            'After' => 'Is after',
            'IsEmpty' => 'Is empty',
            'IsNotEmpty' => 'Is not empty',
            'IsTrue' => 'Is true',
            'IsFalse' => 'Is not true',
        ],
        'hint' => [
            'list' => 'Several values, separated by commas.',
            'date' => 'A date or time, or a variable holding one.',
            'number' => 'Two numbers are compared as numbers, anything else as text.',
        ],
    ],
    'workflow-condition-operator-group' => [
        'label' => [
            'Text' => 'Text',
            'Number' => 'Numbers',
            'Date' => 'Dates',
            'Presence' => 'Presence',
        ],
    ],
    'workflow-condition-outcome' => [
        'label' => [
            'Skip' => 'skip just this step',
            'Stop' => 'stop the whole workflow',
        ],
    ],
    'workflow-list-source' => [
        'label' => [
            'RunningShifts' => 'Shifts still running',
            'OverdueTickets' => 'Tickets past their due date',
            'OpenTickets' => 'Tickets still open',
            'OutstandingContracts' => 'Contracts still out',
            'OpenPolls' => 'Polls still taking votes',
        ],
    ],

    'workflow-record-type' => [
        'label' => [
            'Ticket' => 'ticket',
            'Contract' => 'contract',
            'ContractTemplate' => 'template',
            'Document' => 'document',
            'Poll' => 'poll',
            'ChannelShare' => 'shared channel',
        ],
    ],
    'workflow-run-status' => [
        'label' => [
            'Running' => 'Running',
            'Waiting' => 'Waiting',
            'Succeeded' => 'Done',
            'Stopped' => 'Stopped',
            'Failed' => 'Failed',
        ],
    ],
    'workflow-step-kind' => [
        'label' => [
            'Action' => 'Step',
            'Branch' => 'Fork',
            'Loop' => 'Loop',
        ],
    ],
    'workflow-step-status' => [
        'label' => [
            'Succeeded' => 'Ran',
            'Skipped' => 'Skipped',
            'Failed' => 'Failed',
        ],
    ],
    'workspace-ability' => [
        'label' => [
            'ManageWorkspace' => 'Manage the workspace',
            'InviteMembers' => 'Invite people',
            'SeeMembers' => 'See who takes part',
            'CreateChannels' => 'Make channels',
            'SendTransfers' => 'Send files',
            'BroadcastMention' => 'Use @here and @everyone',
            'ManageWorkflows' => 'Write workflows',
            'CreateForms' => 'Make forms',
            'ShareFormsPublicly' => 'Share forms outside the workspace',
            'SeeHours' => "See colleagues' hours",
            'ManageFeatures' => 'Switch parts of the product on and off',
            'DeleteBotMessages' => 'Delete messages from bots',
            'SendContracts' => 'Have contracts signed',
            'DeleteSignedContracts' => 'Delete signed contracts',
        ],
        'description' => [
            'ManageWorkspace' => 'The name, the roles, the permissions and the look. Whoever holds this can also give themselves and others more — it is the one right that reaches every other.',
            'InviteMembers' => 'Bring somebody in from inside or outside, and withdraw invitations again.',
            'SeeMembers' => 'The member list of the workspace and of a channel. Without it you only see who turns up in the conversation.',
            'CreateChannels' => 'Open a new channel. Taking part in existing ones is a separate matter.',
            'SendTransfers' => 'Put files behind a link that works outside the workspace too.',
            'BroadcastMention' => 'Notify a whole channel at once. Without it those mentions cannot be picked and reach nobody.',
            'ManageWorkflows' => 'Write the things the workspace does by itself. They run with the rights of whoever wrote them.',
            'CreateForms' => 'Put a questionnaire together and place it in a channel. The answers land with whoever made it.',
            'ShareFormsPublicly' => 'Put a form behind a link that works without an account. Whoever holds this lets people from outside write into this workspace.',
            'DeleteBotMessages' => 'Remove messages posted by a webhook or a workflow. Not about what people write themselves — those stay their own. Whoever manages a channel could already do this for that channel.',
            'SeeHours' => 'See how many hours colleagues clocked this week and who is clocked in right now. Everybody sees their own hours regardless; this is about somebody else\'s.',
            'ManageFeatures' => 'Decide which parts this workspace has — documents, contracts, time tracking. Switching one off takes it away from everybody at once; what is already in there is kept, but nobody can open it any more.',
            'SendContracts' => 'Send a PDF with fields on it to people to be signed, outside the workspace too. The document goes out under this workspace\'s name and comes back as evidence.',
            'DeleteSignedContracts' => 'Throw away a contract everybody has already signed, including the signed PDF and the page recording who signed when. It cannot be undone and the signers are not told. Without this right you can only clear away what was never completed.',
        ],
    ],
    'workspace-accent' => [
        'label' => [
            'Neutral' => 'Neutral',
            'Indigo' => 'Indigo',
            'Blue' => 'Blue',
            'Emerald' => 'Green',
            'Amber' => 'Amber',
            'Rose' => 'Pink',
        ],
    ],
    'workspace-font' => [
        'label' => [
            'InstrumentSans' => 'Instrument Sans',
            'Inter' => 'Inter',
            'Figtree' => 'Figtree',
            'Ubuntu' => 'Ubuntu',
            'JetBrainsMono' => 'JetBrains Mono',
            'System' => 'System font',
        ],
    ],
    'system-role' => [
        'getLabel' => [
            'Owner' => 'Owner',
            'Admin' => 'Admin',
            'Member' => 'Member',
            'Guest' => 'Guest',
        ],
    ],
];
