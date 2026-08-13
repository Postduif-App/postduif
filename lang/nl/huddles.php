<?php

/*
 * Wat het kanaal leest over een huddle: dat er opgenomen wordt, en dat het
 * uitgeschreven is.
 *
 * Deze zinnen staan bewust in het kanaal en niet alleen in het huddle-venster.
 * Wie halverwege binnenkomt moet nog steeds kunnen zien dat er opgenomen wordt,
 * en wie er niet bij was moet later kunnen terugvinden dat het gebeurd is.
 */
return [
    'recording' => [
        'started' => ':name is dit gesprek gaan opnemen.',
    ],

    'transcription' => [
        'ready' => "Het gesprek is uitgeschreven:\n\n:excerpt",
        'not_configured' => 'Er is geen transcriptiedienst ingesteld voor deze installatie.',
        'unreadable' => 'De opname was niet meer te lezen toen we hem wilden uitschrijven.',
    ],
];
