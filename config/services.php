<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /**
     * Pushover turns a notification into a push on a member's own phone. The
     * application token identifies Postduif; the key of the device it goes to is a
     * per-member setting.
     */
    'pushover' => [
        'token' => env('PUSHOVER_TOKEN'),
    ],

    /**
     * The identity this installation pushes under, straight to the browser's own
     * push service — no Google or Apple account in between, only the keys below.
     *
     * The public key travels to the browser at subscription time and is public by
     * design; the private one signs every push and stays here. The subject is
     * required by RFC 8292: a mailto: or https: address a push service can use to
     * reach whoever runs this server. Empty keys switch pushes off entirely.
     * Generate a pair with `php artisan webpush:vapid`.
     */
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Where recorded huddles go to be turned into words.
     *
     * An OpenAI-compatible /audio/transcriptions endpoint, which is what both
     * OpenAI itself and the self-hosted whisper servers speak — so a workspace
     * that will not send audio out of the building points the base URL at
     * localhost and changes nothing else.
     *
     * With no base URL configured nothing is transcribed and every recording
     * says so, rather than appearing to have been transcribed into silence.
     * See NullTranscriber.
     */
    'transcription' => [
        'url' => env('TRANSCRIPTION_URL'),
        'token' => env('TRANSCRIPTION_TOKEN'),
        'model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        // Audio is slow. A half-hour meeting takes minutes to come back, and
        // the default of ten seconds would fail every recording worth having.
        'timeout' => (int) env('TRANSCRIPTION_TIMEOUT', 600),
    ],

];
