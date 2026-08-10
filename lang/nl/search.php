<?php

/*
 * Zoeken en springen: het commandopalet (⌘K) en het dialoogje waarin je iemand
 * uitkiest om mee te praten.
 *
 * De filternamen "in:" en "from:" staan hier bewust niet in. Die typen mensen
 * en vertellen ze aan elkaar door; zodra ze meebewegen met de taal van de
 * lezer, stopt de uitleg van een collega met werken.
 */

return [
    'palette' => [
        'title' => 'Zoeken of springen',
        'description' => 'Spring naar een gesprek, start iets, of zoek door alle berichten die je mag lezen',
        'placeholder' => 'Ga naar een kanaal, of zoek in berichten…',
        'empty' => 'Niets gevonden.',
        'remove_filter' => 'Filter :label weghalen',
        'bot' => 'Bot',
        'direct_message' => 'Direct bericht',
        'documents' => '{1}1 document|[2,*]:count documenten',
        'results' => '{1}1 bericht|[2,*]:count berichten',
    ],

    'headings' => [
        'from' => 'Berichten van',
        'searching_in' => 'Zoeken in',
        'quick' => 'Snel naar',
        'jump' => 'Springen naar',
        'actions' => 'Acties',
    ],

    'commands' => [
        'new_channel' => 'Nieuw kanaal',
        'direct_message' => 'Bericht aan iemand',
        'send_files' => 'Bestanden versturen',
        'ask_secret' => 'Om een wachtwoord vragen',
        'send_secret' => 'Een wachtwoord versturen',
        'ask_poll' => 'Een vraag stellen',
        'broadcast' => 'Rondsturen',
        'invite' => 'Iemand uitnodigen',
    ],

    'direct' => [
        'title' => 'Nieuw gesprek',
        'description' => 'Kies met wie je wilt praten. Bestaat het gesprek al, dan open je het gewoon opnieuw.',
        'placeholder' => 'Zoek op naam of @gebruikersnaam…',
        'nobody_yet' => 'Er is nog niemand anders in deze workspace.',
        'none_found' => 'Niemand gevonden.',
    ],
];
