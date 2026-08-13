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
        /*
         * Eén opnemer tegelijk. Niet omdat twee opnames technisch stuk zouden
         * gaan, maar omdat de melding een naam noemt: "Sanne neemt op" terwijl
         * Joost ook opneemt is een melding die stilletjes niet klopt.
         */
        'already' => 'Er wordt al opgenomen in dit gesprek.',
    ],

    'transcription' => [
        'ready' => "Het gesprek is uitgeschreven:\n\n:excerpt",
        'not_configured' => 'Er is geen transcriptiedienst ingesteld voor deze installatie.',
        'unreadable' => 'De opname was niet meer te lezen toen we hem wilden uitschrijven.',
    ],
];
