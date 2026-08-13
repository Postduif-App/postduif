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

        'timeclock' => [
            'label' => 'When somebody clocks in or out',
            'description' => 'Runs the moment somebody puts themselves on the clock or takes themselves off it. Only in workspaces with time tracking switched on.',
            'direction' => [
                'label' => 'On what',
                'hint' => 'With "both" the workflow runs twice per shift: once at the start and once at the end.',
                'both' => 'Clocking in and out',
                'in' => 'Clocking in only',
                'out' => 'Clocking out only',
            ],
        ],

        'contract' => [
            'channel' => [
                'label' => 'In which notify channel',
                'hint' => 'Only contracts whose news goes to this channel. Leave empty for all contracts.',
            ],
            'author' => [
                'label' => 'Asked for by',
                'hint' => 'Only contracts this colleague asked for. Leave empty for everybody.',
            ],
            'words' => [
                'label' => 'Words in the title',
                'hint' => 'Any one of these is enough. Leave empty for every title.',
            ],
        ],

        'contract-sent' => [
            'label' => 'When a contract is sent',
            'description' => 'Runs the moment a contract goes out to its signers.',
        ],
        'contract-opened' => [
            'label' => 'When a signer opens the contract',
            'description' => 'Runs the first time somebody follows their link. Once per signer.',
        ],
        'contract-signed' => [
            'label' => 'When somebody signs a contract',
            'description' => 'Runs on every signature, the last one included. For "everybody has answered", take the completed contract instead.',
        ],
        'contract-declined' => [
            'label' => 'When somebody refuses a contract',
            'description' => 'Runs the moment a signer says no, which closes the whole contract.',
        ],
        'contract-completed' => [
            'label' => 'When a contract is complete',
            'description' => 'Runs once everybody has answered and the signed PDF is ready, so the download link works straight away.',
        ],
        'contract-cancelled' => [
            'label' => 'When a contract is withdrawn',
            'description' => 'Runs the moment the author stops a contract that was out.',
        ],
        'contract-expired' => [
            'label' => 'When a contract expires',
            'description' => 'Runs when the deadline passes without everybody signing. Settled overnight.',
        ],
        'contract-render-failed' => [
            'label' => 'When the signed PDF could not be made',
            'description' => 'Runs when composing the signed copy fails after every attempt. The contract itself is signed.',
        ],

        'ticket' => [
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Only tickets from this channel. Leave empty for every channel in this workspace.',
            ],
        ],

        'ticket-created' => [
            'label' => 'When a ticket comes in',
            'description' => 'Runs the moment somebody opens a ticket, mail included.',
        ],
        'ticket-changed' => [
            'label' => 'When a ticket changes',
            'description' => 'Runs on a new status, another priority, an assignment or a changed deadline.',
            'kind' => [
                'label' => 'On what',
                'hint' => 'With "every change" the workflow also runs when only the deadline moves.',
                'any' => 'Every change',
                'status' => 'The status only',
                'priority' => 'The priority only',
                'assignee' => 'Assigning and unassigning only',
                'due' => 'The deadline only',
            ],
        ],
        'ticket-commented' => [
            'label' => 'When somebody comments on a ticket',
            'description' => 'Runs on every comment. Whether it was the first answer is in the variables.',
        ],
        'ticket-stale' => [
            'label' => 'When a ticket is left sitting',
            'description' => 'Runs on the nightly sweep, at most once a day per ticket.',
            'reason' => [
                'label' => 'On what',
                'hint' => '"Never answered" is the one a customer notices first.',
                'any' => 'Both',
                'overdue' => 'Past the deadline',
                'unanswered' => 'Never answered',
            ],
        ],

        'document' => [
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Only documents from this channel. Leave empty for every channel.',
            ],
        ],
        'document-created' => [
            'label' => 'When a document is started',
            'description' => 'Runs the moment somebody starts a document. Not on the saves after that — those happen by themselves every few seconds.',
        ],
        'document-deleted' => [
            'label' => 'When a document is removed',
            'description' => 'Runs the moment somebody takes a document out of the channel. It is kept, just no longer shown.',
        ],

        'share' => [
            'id' => 'The number of the share',
            'can_post' => 'Whether the guest may join in',
            'guest_id' => 'The number of the other workspace',
            'guest_name' => 'The name of the other workspace',
            'revoked_now' => 'Whether this step ended the share',
        ],
        'await' => [
            'happened' => 'Whether the happening arrived before the time was up',
            'event' => 'What was waited for',
            'record' => 'Which record was waited on',
        ],
        'list' => [
            'index' => 'Which row this is, counting from 1',
            'user_id' => 'The number of the colleague',
            'user_name' => 'The name of the colleague',
            'shift_id' => 'The number of the shift',
            'shift_started_at' => 'When the shift began',
            'shift_hours' => 'How many whole hours the shift has been running',
        ],
        'poll' => [
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Only polls from this channel. Leave empty for every channel.',
            ],
        ],
        'poll-created' => [
            'label' => 'When a poll is started',
            'description' => 'Runs the moment somebody puts a question to a channel.',
        ],
        'poll-voted' => [
            'label' => 'When somebody votes on a poll',
            'description' => 'Runs on every vote, including one being taken off. With a condition on the counts this is your threshold.',
        ],
        'poll-closed' => [
            'label' => 'When a poll is closed',
            'description' => 'Runs when a poll stops taking votes: somebody pressed stop, or its own deadline passed.',
        ],

        'channel-share-offered' => [
            'label' => 'When another workspace shares a channel with us',
            'description' => 'Runs on our side the moment somebody else offers a channel. An invitation is then sitting there to be answered.',
        ],
        'channel-share-answered' => [
            'label' => 'When our shared channel is answered',
            'description' => 'Runs on our side the moment the other workspace says yes or no to a channel we offered.',
            'answer' => [
                'label' => 'On what',
                'hint' => 'With "both" the workflow also runs when they say no.',
                'any' => 'Both',
                'accepted' => 'Only on yes',
                'declined' => 'Only on no',
            ],
        ],
        'channel-share-revoked' => [
            'label' => 'When a shared channel is taken back',
            'description' => 'Runs on our side the moment the other workspace withdraws a channel. Our people are already out of it by then.',
        ],
        'invite-link-redeemed' => [
            'label' => 'When somebody joins through an invitation link',
            'description' => 'Runs the moment somebody becomes a new member through a link. Somebody who was already a member does not count.',
            'role' => [
                'label' => 'With which role',
                'hint' => 'The name of the role the link hands out. Leave empty for any link.',
            ],
        ],
        'transfer-downloaded' => [
            'label' => 'When a transfer is collected',
            'description' => 'Runs the moment somebody downloads files you sent. Only that it happened — never what was in it.',
        ],
        'secret-request-answered' => [
            'label' => 'When a secret request is filled in',
            'description' => 'Runs the moment somebody fills something in. Only how much was answered; the values are encrypted and unreadable to Postduif.',
            'channel' => [
                'label' => 'In which channel',
                'hint' => 'Only requests from this channel. Leave empty for every channel.',
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

        'form-submitted' => [
            'label' => 'When somebody submits a form',
            'description' => 'Runs as soon as an answer arrives on the form you choose.',
            'form' => [
                'label' => 'Which form',
                'hint' => 'One form, because the answers are named differently in every form.',
            ],
            'anonymous' => 'anonymous',
        ],

        'link' => [
            'label' => 'When somebody starts it themselves',
            'description' => 'Appears in the message menu. Whoever picks it there starts the workflow with that message.',
        ],

        'button' => [
            'label' => 'When somebody presses a button',
            'description' => 'Appears as a button in the bar above a channel. You put it there in that channel\'s settings.',
        ],

        'slash-command' => [
            'label' => 'When somebody types a command',
            'description' => 'Appears in the list behind "/" in the message field. Whatever follows the command comes along as text.',
            'command' => [
                'label' => 'Command',
                'hint' => 'Without the slash. Lowercase letters, digits and hyphens, for example report-outage.',
                'malformed' => 'A command is lowercase letters, digits and hyphens, starting with a letter or a digit.',
                'reserved' => '/:command is already a built-in command in the message field.',
                'taken' => '/:command already belongs to another workflow.',
            ],
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
        'create-invite-link' => [
            'label' => 'Make an invitation link',
            'description' => 'Makes a link to let somebody in and leaves the address for a following step. Sends nothing itself.',
            'role' => [
                'label' => 'Which role',
                'hint' => 'The name of a role in this workspace, "Guest" for instance.',
            ],
            'uses' => [
                'label' => 'How often usable',
                'hint' => 'Leave empty for no limit. One is the safest choice.',
            ],
            'days' => [
                'label' => 'Valid for how many days',
                'hint' => 'Leave empty to keep working until you withdraw it.',
            ],
        ],
        'create-secret-request' => [
            'label' => 'Ask for credentials',
            'description' => 'Puts a request for credentials in a channel. Whatever is filled in is encrypted and unreadable to Postduif.',
            'title' => [
                'label' => 'What it is about',
                'hint' => 'For instance: access to the webshop.',
            ],
            'keys' => [
                'label' => 'What you are asking for',
                'hint' => 'One per box, separated by commas. No variables here.',
            ],
            'days' => [
                'label' => 'Valid for how many days',
                'hint' => 'Leave empty for fourteen days.',
            ],
        ],
        'post-to-board' => [
            'label' => 'Post on the board',
            'description' => "Puts a notice on the workspace board, in the name of this workflow's owner.",
            'title' => ['label' => 'Heading'],
        ],
        'forward-message' => [
            'label' => 'Forward a message',
            'description' => 'Forwards the trigger\'s message to another channel, keeping who wrote it.',
            'note' => [
                'label' => 'Note above it',
                'hint' => 'May be left empty; then only the message goes.',
            ],
        ],
        'clock-out' => [
            'label' => 'Clock somebody out',
            'description' => 'Closes the shift that was running. Nothing running means nothing happens.',
            'person' => [
                'label' => 'Who',
                'hint' => 'Leave empty for whoever the trigger was about.',
            ],
        ],
        'summarise-hours' => [
            'label' => 'Add up hours',
            'description' => 'Adds up a week and leaves the result for a following step. Sends nothing itself.',
            'person' => [
                'label' => 'Whose',
                'hint' => 'Leave empty for whoever the trigger was about.',
            ],
            'week' => [
                'label' => 'Which week',
                'hint' => 'A summary you send on Monday is usually about the week before.',
                'this' => 'This week',
                'last' => 'Last week',
            ],
        ],
        'create-document' => [
            'label' => 'Start a document',
            'description' => 'Starts an empty document in a channel. Filling it in is the next step.',
            'title' => [
                'label' => 'Title',
                'hint' => 'You can put trigger data in here.',
            ],
        ],
        'append-to-document' => [
            'label' => 'Add a line to a document',
            'description' => 'Puts a paragraph at the bottom of a document. Useful as a log.',
            'text' => [
                'label' => 'What to add',
                'hint' => 'One paragraph, at the bottom. You can put trigger data in here.',
            ],
        ],
        'create-poll' => [
            'label' => 'Start a poll',
            'description' => 'Puts a question to a channel, with at least two answers.',
            'question' => [
                'label' => 'The question',
                'hint' => 'You can put trigger data in here.',
            ],
            'options' => [
                'label' => 'The answers',
                'hint' => 'At least two, separated by commas. No variables here.',
            ],
            'multiple' => [
                'label' => 'Allow several answers',
                'no' => 'One answer per person',
                'yes' => 'Several answers allowed',
            ],
            'closes' => [
                'label' => 'Close after how many hours',
                'hint' => 'Leave empty to stay open until somebody stops it.',
            ],
        ],
        'close-poll' => [
            'label' => 'Close a poll',
            'description' => 'Stops a poll, so nobody can vote any more.',
        ],
        'update-ticket' => [
            'label' => 'Update a ticket',
            'description' => 'Sets the status, the priority and/or the deadline. Whatever you leave empty stays as it was.',
            'leave_alone' => 'Leave empty to change nothing.',
            'status' => ['label' => 'New status'],
            'priority' => ['label' => 'New priority'],
            'due' => [
                'label' => 'Deadline in how many days',
                'hint' => 'Counted from the moment this step runs.',
            ],
        ],
        'assign-ticket' => [
            'label' => 'Assign a ticket',
            'description' => 'Hands the ticket to a colleague, or takes it off whoever had it.',
            'person' => [
                'label' => 'To whom',
                'hint' => 'Leave empty to take the ticket off whoever had it.',
            ],
        ],
        'comment-on-ticket' => [
            'label' => 'Comment on a ticket',
            'description' => "Puts a comment on the ticket, in the name of this workflow's owner.",
        ],
        'send-contract-from-template' => [
            'label' => 'Send a contract from a template',
            'description' => 'Makes a contract out of a template and sends it to one person. Your side of the template is already signed.',
            'template' => [
                'label' => 'Which template',
                'hint' => 'Only finished templates can be sent.',
            ],
            'name' => [
                'label' => "The signer's name",
                'hint' => 'May be a variable, an answer from a form for instance.',
            ],
            'email' => [
                'label' => "The signer's e-mail address",
                'hint' => 'Where the invitation goes. May be a variable.',
            ],
            'title' => [
                'label' => 'Title of the contract',
                'hint' => "Leave empty for the template's own title.",
            ],
            'days' => [
                'label' => 'Valid for how many days',
                'hint' => 'Leave empty for whatever the template holds.',
            ],
            'channel' => [
                'label' => 'Notify channel',
                'hint' => 'Where news about this contract goes. May be left empty.',
            ],
        ],
        'remind-contract-signers' => [
            'label' => 'Remind the signers',
            'description' => 'Sends the invitation again to everybody who has not answered. Anybody reminded in the last day is skipped.',
        ],
        'post-contract-to-channel' => [
            'label' => 'Post a contract in a channel',
            'description' => "Puts the contract card in a channel, in the name of this workflow's owner.",
        ],
        'add-contract-signer' => [
            'label' => 'Add a signer',
            'description' => 'Puts one more person on the contract, as long as it has not gone out yet.',
            'name' => [
                'label' => 'Name',
                'hint' => 'May come from a variable, for example {{ trigger.answers.naam }}.',
            ],
            'email' => [
                'label' => 'E-mail address',
                'hint' => 'Where the request to sign is sent.',
            ],
        ],
        'duplicate-contract' => [
            'label' => 'Duplicate a contract',
            'description' => 'Makes a fresh draft of the same document, without the original signers and signatures.',
            'title' => [
                'label' => 'Title of the copy',
                'hint' => 'A contract is named once and never renamed, so give the copy a name of its own — Lease {{ trigger.answers.naam }}, for instance.',
            ],
        ],
        'cancel-contract' => [
            'label' => 'Withdraw a contract',
            'description' => 'Stops a contract that is out. The links keep working and say it was withdrawn.',
        ],
        'send-signed-contract' => [
            'label' => 'Send the signed copy',
            'description' => 'Mails the signed PDF to everybody who signed.',
            'again' => [
                'label' => 'Also to whoever had it already',
                'hint' => 'Choose "again" when somebody has lost their copy.',
                'no' => 'Only whoever had none',
                'yes' => 'Again to everybody',
            ],
        ],
        'retry-contract-render' => [
            'label' => 'Try the signed PDF again',
            'description' => 'Queues composing the signed copy once more.',
        ],

        'fields' => [
            'channel' => 'Which channel',
            'person' => 'Who',
            'body' => 'What it will say',
            'body_hint' => 'You can put data from the trigger in here.',
            'message' => 'Which message',
            'message_hint' => 'Leaving this empty means the message the trigger was about.',
            'contract' => 'Which contract',
            'contract_hint' => 'Leave empty for the contract the trigger was about.',
            'ticket' => 'Which ticket',
            'ticket_hint' => 'Leave empty for the ticket the trigger was about.',
            'document' => 'Which document',
            'document_hint' => 'Leave empty for the document the trigger was about.',
            'poll' => 'Which poll',
            'share' => 'Which share',
            'share_hint' => 'Leaving this empty means the share this workflow is about.',
            'poll_hint' => 'Leave empty for the poll the trigger was about.',
            'added' => 'Whether somebody was actually added',
            'thread' => [
                'id' => 'The thread the reply landed in',
            ],
            'emoji' => 'Which emoji',
            'reminded' => 'How many were reminded',
            'copies_sent' => 'How many copies were sent',
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
        'create-ticket' => [
            'label' => 'Open a ticket',
            'description' => "Puts work on a channel's ticket board, in the name of whoever wrote this workflow.",
            'title' => [
                'label' => 'What the ticket is about',
                'hint' => 'The line that appears on the board. You can put things from the trigger in here.',
            ],
            'body' => [
                'label' => 'The description',
            ],
            'priority' => 'How urgent',
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
        /*
         * The four that only look. The description says why they exist, which
         * is not obvious: a run's context is a photograph, and after a Delay the
         * trigger's numbers have stopped moving.
         */
        'read-ticket' => [
            'label' => 'Read a ticket again',
            'description' => 'Changes nothing, but makes the current status, priority and due date available to a later step.',
        ],
        'read-contract' => [
            'label' => 'Read a contract again',
            'description' => 'Changes nothing, but makes it available how many have signed by now and how many days are left.',
        ],
        'read-document' => [
            'label' => 'Read a document again',
            'description' => 'Changes nothing, but makes the current title and number available to a later step.',
        ],
        'read-poll' => [
            'label' => 'Read a poll again',
            'description' => 'Changes nothing, but makes the tally as it stands available: how many votes, and which answer is ahead.',
        ],
        /*
         * Opening a channel to another workspace, and closing it again.
         *
         * The other workspace is named by its slug, in a plain text field — the
         * same thing the button in the panel asks for. Nothing is granted: the
         * other side still has to say yes.
         */
        'share-channel' => [
            'label' => 'Share a channel with a workspace',
            'description' => 'Offers this channel to another workspace. They still have to accept before anybody reads along.',
            'workspace' => [
                'label' => 'Which workspace',
                'hint' => 'The slug of the other workspace, or a variable holding it.',
            ],
            'can_post' => [
                'label' => 'What they may do',
                'hint' => 'Leave this alone and they may join in.',
                'yes' => 'Read and write',
                'no' => 'Read only',
            ],
        ],
        'revoke-channel-share' => [
            'label' => 'End a share with a workspace',
            'description' => 'Ends the arrangement and takes out everybody who was only in the channel through it. What was said stays.',
        ],
        'wait-for-event' => [
            'label' => 'Wait for something to happen',
            'description' => 'Holds the workflow until this happens, or until the time is up. Which of the two ends up in {{ steps.N.happened }}.',
            'event' => [
                'label' => 'What to wait for',
                'hint' => 'Only happenings about a single record: waiting for something with no record cannot be recognised when it arrives.',
            ],
            'minutes' => [
                'label' => 'At most how many minutes',
                'hint' => 'There is always a deadline. When it passes the workflow carries on with happened set to false.',
            ],
            'record' => [
                'label' => 'About which record',
                'hint' => 'Leave empty for the record this workflow started from. Otherwise a variable, for example {{ steps.1.contract.id }}.',
            ],
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

    'config' => [
        'no_variables' => 'This field cannot hold a variable.',
        'not_text' => 'This field expects text.',
        'too_long' => 'This field may hold at most :max characters.',
        'not_a_number' => 'This field expects a number.',
        'not_words' => 'This field expects a list of words.',
        'too_many_words' => 'At most :max words.',
        'unknown_choice' => 'Choose one of the options in the list.',
        'channel_not_found' => 'That channel does not belong to this workspace.',
        'member_not_found' => 'That person is not in this workspace.',
        'form_not_found' => 'That form does not belong to this workspace.',
        'record_not_found' => 'That is not a :what you can choose here.',
    ],
    'errors' => [
        'board_off' => 'This workspace no longer has a board.',
        'forwarding_off' => 'This workspace no longer forwards messages.',
        'secrets_off' => 'This workspace no longer asks for credentials.',
        'invite_links_off' => 'This workspace no longer uses invitation links.',
        'empty_board_post' => 'A board notice needs a heading and text.',
        'empty_secret_request' => 'A request needs a description and at least one thing to ask for.',
        'may_not_ask_secrets' => 'The owner of this workflow may not post in #:channel.',
        'may_not_invite' => 'The owner of this workflow may not invite anybody.',
        'no_role_named' => 'No role was given for the invitation link.',
        'role_not_found' => 'This workspace has no role called ":role".',
        'timeclock_off' => 'This workspace no longer keeps time.',
        'no_person_anywhere' => 'This step is about a person, but none was chosen and the trigger brought none either.',
        'documents_off' => 'This workspace no longer keeps documents.',
        'polls_off' => 'This workspace no longer keeps polls.',
        'may_not_create_document' => 'The owner of this workflow may not start a document in #:channel.',
        'may_not_write_document' => 'The owner of this workflow may not write in ":title".',
        'may_not_close_poll' => 'The owner of this workflow may not close this poll.',
        'empty_document_title' => 'No title was left for the document.',
        'empty_document_text' => 'No text was left to add to the document.',
        'document_busy' => '":title" is being written in right now, so the line was not added.',
        'empty_question' => 'No question was left to ask.',
        'too_few_options' => 'A poll needs at least two answers.',
        'may_not_manage_ticket' => 'The owner of this workflow may not update ticket #:number.',
        'may_not_comment_on_ticket' => 'The owner of this workflow may not comment on ticket #:number.',
        'assignee_cannot_see_ticket' => ':name cannot see this ticket, so it was not assigned to them.',
        'nothing_to_change' => 'This step would change nothing: fill in a status, a priority or a deadline.',
        'empty_comment' => 'No text was left to put on the ticket.',
        'contracts_off' => 'This workspace no longer asks for signatures.',
        'may_not_touch_contract' => 'The owner of this workflow may not do this to ":title".',
        'may_not_send_contract' => 'The owner of this workflow may not send contracts.',
        'template_unfinished' => 'The template ":title" is not finished: fields or a signature are missing.',
        'template_wants_more_signers' => 'The template ":title" is for :count signers, and this step sends to one.',
        'bad_signer_email' => '":email" is not a usable e-mail address.',
        'no_signer_name' => 'No name was left for the signer.',
        'signer_is_sender' => 'That address already signed the template itself (:email).',
        'signer_already_on' => ':email is already on this contract.',
        'contract_already_sent' => '":title" has already gone out, so nobody can be added to it.',
        'nothing_to_duplicate' => 'There is no document to copy from ":title".',
        'no_contract_title' => 'Nothing was left of the title for the copy.',
        'nothing_to_render' => 'There is nothing to compose for ":title": the contract is not finished.',
        'no_channel_chosen' => 'This step was given no channel.',
        'channel_not_found' => 'That channel is gone, or this workflow\'s owner cannot reach it.',
        'no_message' => 'This step is about a message, but there is none.',
        'message_not_found' => 'That message is gone.',
        'no_person_chosen' => 'This step was given no person.',
        'record_feature_off' => ':what is not switched on in this workspace.',
        'shared_channels_off' => 'Shared channels are switched off in this workspace.',
        'not_our_channel' => 'This channel does not belong to this workspace, so it cannot be shared on from here.',
        'may_not_share_channel' => 'The owner of this workflow may not share this channel.',
        'may_not_sever_share' => 'The owner of this workflow may not end this share.',
        'no_workspace_named' => 'No workspace was named to share with.',
        'workspace_not_found' => 'There is no workspace with the slug :slug.',
        'no_event_chosen' => 'Nothing was chosen to wait for.',
        'no_record_to_await' => 'This step waits for :what, but none was named and the trigger brought none either.',
        'no_list_chosen' => 'This loop has no list to walk.',
        'list_feature_off' => ':what cannot be walked: that part of the workspace is switched off.',
        'no_record' => 'This step is about :what, but none was named and the trigger brought none either.',
        'record_not_found' => 'That is not a :what of this workspace, or the owner of this workflow may not see it.',
        'person_not_found' => 'That person is no longer in this workspace.',
        'tickets_off' => 'This workspace no longer keeps tickets.',
        'may_not_open_ticket' => 'The owner of this workflow may not open a ticket in :channel.',
        'empty_ticket_title' => 'Nothing was left to name the ticket after.',
        'no_owner' => 'This workflow has no owner left.',
        'may_not_post' => 'This workflow\'s owner may not post in #:channel.',
        'may_not_dm' => 'This workflow\'s owner may not message this person.',
        'dm_to_self' => 'This step points at the workflow\'s own owner, and a DM with yourself does not exist.',
        'may_not_pin' => 'This workflow\'s owner may not pin anything here.',
        'may_not_archive' => 'This workflow\'s owner may not archive this channel.',
        'may_not_add_members' => 'This workflow\'s owner may not add anybody here.',
        'may_not_create_channel' => 'This workflow\'s owner may not create channels.',
        'no_channel_name' => 'No name was left for the channel.',
        'empty_message' => 'No text was left to send.',
        'url_unreadable' => 'That is not an address Postduif can do anything with.',
        'url_scheme' => 'Only http:// and https:// can be requested.',
        'url_not_public' => "This address is inside the server's own network. A workflow may not request that.",
        'url_unknown_host' => 'That address does not exist, or is not answering right now.',
        'http_method' => 'Postduif does not know that kind of request.',
        'http_unreachable' => 'Nothing came back. The address took too long or cannot be reached.',
        'delay_too_short' => 'Waiting takes at least a minute.',
        'delay_too_long' => 'Waiting longer than four weeks is not possible.',
    ],

    'webhook' => [
        'unknown' => 'Unknown workflow.',
    ],

    'screen' => [
        'avatar' => "The bot's face",
        'avatar_hint' => 'Appears beside every message this workflow posts. Without a picture the default bot mark stays.',
        'avatar_choose' => 'Choose a picture',
        'avatar_remove' => 'Remove the picture',
        'avatar_saved' => "The bot's face has been updated.",
        'avatar_removed' => 'The picture has been removed.',
        'created' => 'Workflow created. Switch it on once the steps are right.',
        'saved' => 'Workflow saved.',
        'deleted' => 'Workflow deleted.',
        'too_many' => 'More than :count workflows in one workspace is more than anybody can keep track of.',
        'no_steps' => 'This workflow has no steps yet, so there is nothing to switch on.',
        'too_many_steps' => 'More than :count steps in one workflow is more than anybody can follow.',
    ],

    'command' => [
        'unknown' => 'No workflow answers to /:command.',
    ],

    'link' => [
        'started' => '“:name” has been started.',
        'refused' => 'This workflow could not start just now.',
    ],

    'run' => [
        'no_longer_allowed' => 'This workflow is switched off or has no owner left, so the rest was not carried out.',
        'unknown_action' => 'This step does something (:action) Postduif no longer knows.',
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
        'share' => [
            'id' => 'The share',
            'can_post' => 'Whether the guests may join in',
            'accepted' => 'Whether the offer was taken up',
            'channel_id' => 'The shared channel',
            'channel_name' => 'The name of the shared channel',
            'host_id' => 'The workspace that owns the channel',
            'host_name' => 'The name of that workspace',
            'guest_id' => 'The workspace that is the guest',
            'guest_name' => 'The name of that workspace',
        ],
        'link' => [
            'id' => 'The invitation link',
            'url' => 'The address of the link',
            'role' => 'The role the link hands out',
            'uses' => 'How often the link was used',
            'uses_left' => 'How often the link can still be used',
            'expires_at' => 'Until when the link works',
        ],
        'transfer' => [
            'id' => 'The transfer',
            'title' => 'The title of the transfer',
            'downloads' => 'How often it was downloaded',
            'expires_at' => 'Until when the transfer works',
            'sender_id' => 'Who sent it',
            'sender_name' => 'The name of whoever sent it',
            'recipient_id' => 'The recipient who collected it',
            'recipient_email' => "That recipient's address",
        ],
        'secret' => [
            'id' => 'The request',
            'title' => 'What the request is about',
            'url' => 'The link to fill it in',
            'answered' => 'How much was filled in now',
            'outstanding' => 'How much is still open',
            'is_complete' => 'Whether everything is filled in',
            'requester_id' => 'Who asked',
            'requester_name' => 'The name of whoever asked',
        ],
        'board' => [
            'id' => 'The board notice',
            'title' => 'The heading of the board notice',
        ],
        'hours' => [
            'total' => 'The hours in that week',
            'spoken' => 'Those hours as a sentence',
            'days_worked' => 'How many days were worked',
            'from' => 'The Monday of that week',
            'until' => 'The Sunday of that week',
        ],
        'document' => [
            'id' => 'The document',
            'number' => 'The document number',
            'title' => 'The document title',
            'actor_id' => 'Who did it',
            'actor_name' => 'The name of whoever did it',
        ],
        'poll' => [
            'id' => 'The poll',
            'question' => 'The question',
            'url' => 'Link to the poll',
            'option_count' => 'Number of answers',
            'vote_count' => 'Number of votes',
            'voter_count' => 'How many people voted',
            'leading_option' => 'The answer in front',
            'top_votes' => 'Votes on the answer in front',
            'is_closed' => 'Whether the poll is closed',
            'closes_at' => 'When the poll closes',
            'closed_now' => 'Whether this step actually closed it',
            'asker_id' => 'Who asked',
            'asker_name' => 'The name of whoever asked',
            'vote_ticked' => 'Whether the vote went on or came off',
            'option_id' => 'The answer that was voted on',
            'option_label' => 'The text of that answer',
            'option_votes' => 'Votes on that answer',
            'voter_id' => 'Who voted',
            'voter_name' => 'The name of whoever voted',
        ],
        'contract' => [
            'id' => 'The contract',
            'title' => 'The contract title',
            'status' => 'Where the contract stands',
            'url' => 'Link to the contract in Postduif',
            'download_url' => 'Link to download the signed PDF',
            'expires_at' => 'The deadline',
            'days_until_expiry' => 'Days until the deadline',
            'page_count' => 'Number of pages',
            'signer_count' => 'Number of signers',
            'signed_count' => 'How many have signed',
            'declined_count' => 'How many have refused',
            'remaining' => 'How many still have to answer',
            'signers' => 'The signers by name',
            'author_id' => 'Who asked for it',
            'author_name' => 'The name of whoever asked',
            'channel_id' => 'The notify channel',
            'channel_name' => 'The name of the notify channel',
        ],
        'signer' => [
            'id' => 'The signer',
            'name' => 'The signer\'s name',
            'email' => 'The signer\'s e-mail address',
            'order' => 'Their place in the queue',
            'is_external' => 'Whether they are from outside',
            'is_last' => 'Whether they were the last to answer',
            'decline_reason' => 'The reason given for refusing',
        ],
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
        'ticket' => [
            'title' => 'The ticket title',
            'body' => 'The ticket description',
            'status' => 'The ticket status',
            'priority' => 'The priority',
            'due_at' => 'The deadline',
            'hours_open' => 'How many hours the ticket has been open',
            'is_overdue' => 'Whether the deadline has passed',
            'has_assignee' => 'Whether somebody has it',
            'answered' => 'Whether anybody has replied yet',
            'assignee_id' => 'Who has the ticket',
            'assignee_name' => 'The name of whoever has it',
            'reporter_id' => 'Who opened the ticket',
            'reporter_name' => 'The name of whoever opened it',
            'actor_id' => 'Who made this change',
            'actor_name' => 'The name of whoever made this change',
            'change_kind' => 'What changed',
            'change_from' => 'What it was',
            'change_to' => 'What it is now',
            'comment_id' => 'The comment',
            'comment_body' => 'The text of the comment',
            'comment_first' => 'Whether this was the first reply',
            'comment_author_id' => 'Who commented',
            'comment_author_name' => 'The name of whoever commented',
            'stale_reason' => 'Why it was left sitting',
            'id' => 'The ticket',
            'number' => 'The number of the ticket',
        ],
        'channel' => [
            'topic' => 'The topic of the channel',
            'members' => 'How many members the channel has',
            'archived' => 'Whether the channel is archived',
            'id' => 'The channel',
            'name' => 'The name of the channel',
        ],
        'punch' => [
            'direction' => 'Whether it was clocking in or out',
            'at' => 'At what time, on that person\'s own clock',
        ],
        'shift' => [
            'hours' => 'How long the shift lasted, in hours (7.5)',
            'duration' => 'How long the shift lasted, written out',
            'started_at' => 'What time the shift began',
            'was_running' => 'Whether a shift was really running',
        ],
        'user' => [
            'id' => 'Who did it',
            'name' => 'The name of who did it',
        ],
        'form' => [
            'id' => 'The form',
            'title' => 'The name of the form',
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
        'command' => 'The command that was typed, without the slash',
        'arguments' => 'Whatever followed the command',
        'emoji' => 'The emoji that was used',
        'keyword' => 'The word that was found',
        'answers' => 'All the answers. Put the key of a question after it for one answer on its own, for instance answers.reden',
        'payload' => 'Everything that arrived',
    ],
];
