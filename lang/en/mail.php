<?php

return [
    'closing' => 'Regards,',

    'invitation' => [
        'heading' => 'You have been invited',
        'intro' => ':inviter is inviting you to **:workspace**.',
        'guest' => 'You are joining as a guest. That means you only see the channels you were invited to — the rest of the workspace stays out of view.',
        'channels' => 'You will get access to:',
        'button' => 'Accept invitation',
        'expires' => 'This link expires on :date. Was this invitation not meant for you? Then there is nothing you need to do.',
    ],

    'contract' => [
        'heading' => 'There is a contract waiting for your signature',
        'intro' => ':sender is asking you to sign ":title".',
        'button' => 'Open the contract and sign',
        'expires' => 'This link expires on :date. After that you can no longer sign and a new request has to be sent.',
        'personal' => 'This link is personal and in your name. Do not forward it — whoever opens it signs as you.',
    ],

    'transfer' => [
        'heading' => 'There are files waiting for you',
        'intro' => '{1}:sender has put a file ready for you.|[2,*]:sender has put :count files ready for you.',
        'button' => 'Download files',
        'expires' => 'This link was made for you and expires on :date. After that the files are gone. Did this arrive unexpectedly? Then there is nothing you need to do.',
    ],

];
