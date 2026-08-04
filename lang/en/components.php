<?php

return [
    'user_menu' => [
        'set_status' => 'Set a status',
        'appearance' => 'Appearance',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
        'settings' => 'Settings',
        'log_out' => 'Log out',
    ],

    'avatar' => [
        'hint' => 'png, jpg, gif or webp, up to 2 MB. Cropped to a square.',
        'choose' => 'Choose a picture',
        'replace' => 'Another picture',
        'remove' => 'Remove',
    ],

    'locale' => [
        'label' => 'Language',
        'follow_browser' => 'Follow my browser',
    ],

    'timezone' => [
        'label' => 'Time zone',
        'hint' => 'The clock repeating times are read on, such as a status that starts at nine every working day.',
        'detected' => 'Your browser is set to :zone.',
        'adopt' => 'Use that one',
    ],

    'guest_channels' => [
        'title' => ":name's channels",
        'description' => 'A guest only sees what is ticked here — public channels are not theirs to find.',
        'empty' => 'There are no channels to add this guest to yet.',
    ],

    'passkeys' => [
        'title' => 'Passkeys',
        'description' => 'Your passkeys, for signing in without a password',
        'empty' => 'No passkeys yet',
        'empty_hint' => 'Add a passkey to sign in without a password',
        'unsupported' => 'This browser doesn\'t support passkeys.',
        'add' => 'Add a passkey',
        'name' => 'Name for this passkey',
        'name_placeholder' => 'For example: MacBook Pro, iPhone',
        'name_hint' => 'A name helps you recognise this passkey later.',
        'registering' => 'Adding…',
        'register' => 'Save passkey',
        'cancel' => 'Cancel',
    ],

    'two_factor' => [
        'title' => 'Two-step verification',
        'description' => 'A second step when you log in',
        'enabled_explanation' => 'When you log in we ask for a code from your authenticator app.',
        'disabled_explanation' => 'Turn this on and we will ask for a code from your authenticator app when you log in.',
        'disable' => 'Turn off',
        'enable' => 'Turn on',
        'continue_setup' => 'Continue setting up',

        'modal' => [
            'enabled_title' => 'Two-step verification is on',
            'enabled_description' => 'Scan the QR code or enter the key in your authenticator app.',
            'enabled_button' => 'Close',
            'verify_title' => 'Check the code',
            'verify_description' => 'Enter the six-digit code your authenticator app shows',
            'setup_title' => 'Turn on two-step verification',
            'setup_description' => 'Scan the QR code or enter the key in your authenticator app to finish',
            'continue' => 'Continue',
            'manual' => 'or enter the key yourself',
            'copy_key' => 'Copy the key',
            'back' => 'Back',
            'confirm' => 'Confirm',
        ],
    ],

    'dev_login' => [
        'notice' => 'Development only — sign in directly',
    ],

    'shell' => [
        'platform' => 'Platform',
        'navigation' => 'Navigation menu',
        'dashboard' => 'Dashboard',
        'repository' => 'Repository',
        'documentation' => 'Documentation',
    ],
];
