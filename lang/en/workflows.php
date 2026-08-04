<?php

return [

    'triggers' => [

        'message-keyword' => [
            'label' => 'When somebody says a word',
            'description' => 'Runs as soon as a message is posted containing one of your words.',
            'keywords' => [
                'label' => 'Words',
                'hint' => 'One at a time. Capitals make no difference.',
            ],
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Leaving this empty means anywhere in this workspace.',
            ],
        ],

        'channel-join' => [
            'label' => 'When somebody joins a channel',
            'description' => 'Runs as soon as somebody joins a channel, including when they had been in it before.',
            'channel' => [
                'label' => 'Which channel',
                'hint' => 'Leaving this empty means every channel in this workspace.',
            ],
        ],

        'reaction' => [
            'label' => 'When somebody uses an emoji',
            'description' => 'Runs as soon as this emoji is put on a message. Removing and re-adding it runs the workflow again.',
            'emoji' => [
                'label' => 'Which emoji',
                'hint' => 'Only this one sets the workflow off.',
            ],
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Leaving this empty means anywhere in this workspace.',
            ],
        ],

        'link' => [
            'label' => 'When somebody starts it themselves',
            'description' => 'Appears in the message menu. Whoever picks it there starts the workflow with that message.',
        ],

        'webhook' => [
            'label' => 'When something arrives at a URL',
            'description' => 'You get a secret URL. Anything sent to it sets the workflow off.',
        ],

        'schedule' => [
            'label' => 'At a set time',
            'description' => 'Runs by itself, at the rhythm you choose. The time is your own clock — the time zone in your profile.',
            'cadence' => [
                'label' => 'How often',
                'hourly' => 'Every hour',
                'daily' => 'Every day',
                'weekly' => 'Every week',
            ],
            'time' => [
                'label' => 'At what time',
                'hint' => 'Like 09:00. You can leave this empty for every hour.',
            ],
            'weekday' => [
                'label' => 'On which day',
                'hint' => 'Only needed for every week.',
            ],
        ],
    ],

    'actions' => [

        'fields' => [
            'channel' => 'Which channel',
            'person' => 'Who',
            'body' => 'What it will say',
            'body_hint' => 'You can put data from the trigger in here.',
            'message' => 'Which message',
            'message_hint' => 'Leaving this empty means the message the trigger was about.',
            'added' => 'Whether somebody was actually added',
            'thread' => [
                'id' => 'The thread the reply landed in',
            ],
            'emoji' => 'Which emoji',
            'channel_name' => 'Name of the channel',
            'channel_name_hint' => 'May hold data from the trigger, for instance the name of whoever asked.',
            'channel_type' => 'Who may join',
            'topic' => 'Topic',
        ],

        'send-channel-message' => [
            'label' => 'Message in a channel',
            'description' => 'Posts a message under this workflow\'s name, recognisable as a bot.',
        ],
        'send-direct-message' => [
            'label' => 'Message to a person',
            'description' => 'Sends a DM. The conversation runs through this workflow\'s owner.',
        ],
        'reply-in-thread' => [
            'label' => 'Reply in a thread',
            'description' => 'Hangs a reply under a message instead of beside it.',
        ],
        'add-reaction' => [
            'label' => 'Put an emoji on a message',
            'description' => 'Reacts on behalf of this workflow\'s owner.',
        ],
        'remove-reaction' => [
            'label' => 'Take an emoji off',
            'description' => 'Only removes this workflow owner\'s own reaction.',
        ],
        'pin-message' => [
            'label' => 'Pin a message',
            'description' => 'Puts a message at the top of its channel.',
        ],
        'unpin-message' => [
            'label' => 'Unpin a message',
            'description' => 'Takes a message back off the pinned list.',
        ],
        'create-channel' => [
            'label' => 'Create a channel',
            'description' => 'Opens a new channel. The steps after it can use it straight away.',
            'public' => 'Everyone in the workspace',
            'private' => 'Only the people you add',
        ],
        'add-channel-members' => [
            'label' => 'Add somebody to a channel',
            'description' => 'Puts one person in a channel. If they were already in it, nothing happens.',
        ],
        'get-channel-info' => [
            'label' => 'Get channel details',
            'description' => 'Changes nothing, but makes the name, topic and member count available to a later step.',
        ],
        'archive-channel' => [
            'label' => 'Archive a channel',
            'description' => 'Closes a channel. Everything stays readable, nobody can post.',
        ],
        'unarchive-channel' => [
            'label' => 'Open a channel again',
            'description' => 'Takes a channel back out of the archive.',
        ],
        'http-request' => [
            'label' => 'Make an HTTP request',
            'description' => 'Asks another system something and remembers the answer, so a later step can carry on with it.',
            'method' => [
                'label' => 'What kind of request',
            ],
            'url' => [
                'label' => 'To which URL',
                'hint' => 'Must start with https:// and live outside this network. You may put data from earlier steps in it.',
            ],
            'headers' => [
                'label' => 'Headers',
                'hint' => 'One per line, as "Authorization: Bearer abc". This is usually where your key goes.',
            ],
            'body' => [
                'label' => 'What you send along',
                'hint' => 'Usually JSON. Stays empty on a GET. Data from earlier steps may go in it.',
            ],
        ],
        'delay' => [
            'label' => 'Wait',
            'description' => 'Puts the workflow down and picks it up later.',
            'minutes' => [
                'label' => 'How many minutes',
                'hint' => 'An hour is 60, a day 1440. Four weeks at most.',
            ],
        ],
    ],

    'errors' => [
        'no_channel_chosen' => 'This step was given no channel.',
        'channel_not_found' => 'That channel is gone, or this workflow\'s owner cannot reach it.',
        'no_message' => 'This step is about a message, but there is none.',
        'message_not_found' => 'That message is gone.',
        'no_person_chosen' => 'This step was given no person.',
        'person_not_found' => 'That person is no longer in this workspace.',
        'no_owner' => 'This workflow has no owner left.',
        'may_not_post' => 'This workflow\'s owner may not post in #:channel.',
        'may_not_dm' => 'This workflow\'s owner may not message this person.',
        'may_not_pin' => 'This workflow\'s owner may not pin anything here.',
        'may_not_archive' => 'This workflow\'s owner may not archive this channel.',
        'may_not_add_members' => 'This workflow\'s owner may not add anybody here.',
        'may_not_create_channel' => 'This workflow\'s owner may not create channels.',
        'no_channel_name' => 'No name was left for the channel.',
        'empty_message' => 'No text was left to send.',
        'url_unreadable' => 'That is not an address Pcom can do anything with.',
        'url_scheme' => 'Only http:// and https:// can be requested.',
        'url_not_public' => "This address is inside the server's own network. A workflow may not request that.",
        'url_unknown_host' => 'That address does not exist, or is not answering right now.',
        'http_method' => 'Pcom does not know that kind of request.',
        'http_unreachable' => 'Nothing came back. The address took too long or cannot be reached.',
        'delay_too_short' => 'Waiting takes at least a minute.',
        'delay_too_long' => 'Waiting longer than four weeks is not possible.',
    ],

    'webhook' => [
        'unknown' => 'Unknown workflow.',
    ],

    'screen' => [
        'created' => 'Workflow created. Switch it on once the steps are right.',
        'saved' => 'Workflow saved.',
        'deleted' => 'Workflow deleted.',
        'too_many' => 'More than :count workflows in one workspace is more than anybody can keep track of.',
        'no_steps' => 'This workflow has no steps yet, so there is nothing to switch on.',
        'too_many_steps' => 'More than :count steps in one workflow is more than anybody can follow.',
    ],

    'link' => [
        'started' => '“:name” has been started.',
        'refused' => 'This workflow could not start just now.',
    ],

    'run' => [
        'no_longer_allowed' => 'This workflow is switched off or has no owner left, so the rest was not carried out.',
        'unknown_action' => 'This step does something (:action) Pcom no longer knows.',
        'step_failed' => 'This step went wrong.',
        'went_round_in_circles' => 'This workflow runs in a circle and has been stopped.',
    ],

    'value' => [
        'yes' => 'yes',
        'no' => 'no',
        // What is left where an answer was cut off, so half a sentence does
        // not read as the whole of one.
        'truncated' => '… (cut off)',
    ],

    'weekdays' => [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ],

    'provides' => [
        'http' => [
            'status' => 'The status code of the answer',
            'ok' => 'Whether the request worked',
            'json' => 'The answer (JSON)',
            'body' => 'The answer as text',
        ],
        'message' => [
            'id' => 'The message',
            'text' => 'What the message says',
        ],
        'channel' => [
            'topic' => 'The topic of the channel',
            'members' => 'How many members the channel has',
            'archived' => 'Whether the channel is archived',
            'id' => 'The channel',
            'name' => 'The name of the channel',
        ],
        'user' => [
            'id' => 'Who did it',
            'name' => 'The name of who did it',
        ],
        'reactor' => [
            'id' => 'Who put the emoji there',
            'name' => 'The name of who put the emoji there',
        ],
        'starter' => [
            'id' => 'Who started the workflow',
            'name' => 'The name of who started the workflow',
        ],
        'author' => [
            'id' => 'Who wrote the message',
            'name' => 'The name of who wrote the message',
        ],
        'moment' => [
            'date' => 'Today\'s date',
            'time' => 'What time it is',
        ],
        'emoji' => 'The emoji that was used',
        'keyword' => 'The word that was found',
        'payload' => 'Everything that arrived',
    ],
];
