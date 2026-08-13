<?php

return [
    /*
     * Post that comes in and becomes a ticket. One sentence, and a necessary
     * one: a mail with no subject would otherwise make a ticket with no name,
     * and nobody can refer to that.
     */
    'inbound' => [
        'no_subject' => 'Message without a subject',
    ],

    'closing' => 'Regards,',

    'format' => [
        'date' => 'j F Y',
        'date_time' => 'j F Y \a\t H:i',
    ],

    'invitation' => [
        'heading' => 'You have been invited',
        'intro' => ':inviter is inviting you to **:workspace**.',
        'guest' => 'You are joining as a guest. That means you only see the channels you were invited to — the rest of the workspace stays out of view.',
        'channels' => 'You will get access to:',
        'button' => 'Accept invitation',
        'expires' => 'This link expires on :date. Was this invitation not meant for you? Then there is nothing you need to do.',
    ],

    'contract' => [
        'subject' => '{{sender}} is asking you to sign {{title}}',
        'heading' => 'There is a contract waiting for your signature',
        'button' => 'Open the contract and sign',
        'body' => <<<'MARKDOWN'
            {{sender}} is asking you to sign "{{title}}".

            > {{message}}

            {{button}}

            This link expires on {{expires}}. After that you can no longer sign and a new request has to be sent.

            This link is personal and in your name. Do not forward it — whoever opens it signs as you.

            Regards,
            {{sender}}
            MARKDOWN,
    ],

    'contract_signed' => [
        'subject' => 'The signed document: {{title}}',
        'heading' => 'Here is the signed document',
        'button' => 'Download the signed version',
        'body' => <<<'MARKDOWN'
            Everybody has answered "{{title}}". Attached is the signed version, with a summary at the back of who signed and when.

            You signed on {{signed_at}}.

            The PDF is attached to this mail. Keep it somewhere safe: this is your copy.

            {{button}}

            If the attachment does not work, you can fetch the document with the button above. That link is personal — do not forward it.

            Regards,
            {{sender}}
            MARKDOWN,
    ],

    'transfer' => [
        'heading' => 'There are files waiting for you',
        'intro' => '{1}:sender has put a file ready for you.|[2,*]:sender has put :count files ready for you.',
        'button' => 'Download files',
        'expires' => 'This link was made for you and expires on :date. After that the files are gone. Did this arrive unexpectedly? Then there is nothing you need to do.',
    ],

    'test' => [
        'subject' => 'Test message from :workspace',
        'heading' => 'This works',
        'intro' => 'This message was sent through the mail settings of **:workspace**. If it arrived, so will the rest.',
        'sender' => 'Have a look at the sender above too: that is the address this workspace mails from from now on.',
    ],
];
