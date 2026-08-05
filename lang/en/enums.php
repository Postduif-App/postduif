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
    'inbox-item-type' => [
        'label' => [
            'Mention' => 'Mentioned',
            'Reply' => 'Reply',
            'ThreadReply' => 'Thread',
            'PollVote' => 'Poll',
        ],
    ],
    'member-panel-visibility' => [
        'label' => [
            'Off' => 'Off',
            'Everyone' => 'Everyone in the workspace',
            'Admins' => 'Only admins and the owner',
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
            'IsEmpty' => 'Is empty',
            'IsNotEmpty' => 'Is not empty',
        ],
    ],
    'workflow-condition-outcome' => [
        'label' => [
            'Skip' => 'skip just this step',
            'Stop' => 'stop the whole workflow',
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
        ],
        'description' => [
            'ManageWorkspace' => 'The name, the roles, the permissions and the look. Whoever holds this can also give themselves and others more — it is the one right that reaches every other.',
            'InviteMembers' => 'Bring somebody in from inside or outside, and withdraw invitations again.',
            'SeeMembers' => 'The member list of the workspace and of a channel. Without it you only see who turns up in the conversation.',
            'CreateChannels' => 'Open a new channel. Taking part in existing ones is a separate matter.',
            'SendTransfers' => 'Put files behind a link that works outside the workspace too.',
            'BroadcastMention' => 'Notify a whole channel at once. Without it those mentions cannot be picked and reach nobody.',
            'ManageWorkflows' => 'Write the things the workspace does by itself. They run with the rights of whoever wrote them.',
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
