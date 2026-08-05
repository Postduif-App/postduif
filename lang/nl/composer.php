<?php

/*
 * The message field, and everything hanging off it.
 *
 * The markdown hints are in here too, and that is deliberate: "**vet**" is a
 * word an English reader has to guess at, and the syntax markers around it are
 * the same either way — so it is the word between them that gets translated,
 * not the asterisks.
 */

return [
    'suggestions' => [
        'command' => 'Kies een opdracht',
        'channel' => 'Verwijs naar een kanaal',
        'member' => 'Vermeld een lid',
        'custom_emoji' => 'emoji van deze workspace',
        'here' => 'wie dit kanaal nu open heeft',
        'everyone' => 'alle :count leden',
    ],

    'quote' => [
        'cancel' => 'Citaat weghalen',
    ],

    'schedule' => [
        'later' => 'Later versturen',
        'at' => 'Versturen op',
        'send_now' => 'Toch nu versturen',
        'send' => 'Verstuur bericht',
        'plan' => 'Bericht inplannen',
    ],

    'recording' => [
        'in_progress' => 'Aan het opnemen…',
        'discard' => 'Opname weggooien',
        'start' => 'Spraakbericht opnemen',
        'stop' => 'Opname stoppen en meesturen',
    ],

    'attachment' => [
        'add' => 'Bestand meesturen',
        'not_when_scheduled' => 'Een ingepland bericht kan geen bestand meesturen',
        'too_large' => 'Te groot om mee te sturen: :files. Het maximum is :max.',
        'use_transfer' => 'Groter versturen kan met /versturen — dan komt er een downloadlink in het kanaal.',
    ],

    'hints' => [
        'send' => 'verstuurt',
        'newline' => 'nieuwe regel',
        'member' => 'lid',
        'channel' => 'kanaal',
        'bold' => '**vet**',
        'italic' => '*cursief*',
        'strike' => '~~doorhalen~~',
        'code' => '`code`',
        'code_block' => '```blok```',
    ],
];
