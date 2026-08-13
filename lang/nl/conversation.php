<?php

/*
 * The channel header, the panels beside it, and the slash commands.
 *
 * The command names themselves are not in here. "/versturen" is something
 * people type and tell each other — translating it would mean a colleague's
 * instructions stop working the moment somebody switches language, and a
 * message saying "typ /versturen" would be wrong for half the workspace.
 */

return [
    'view' => [
        'documents' => 'Documenten',
        'label' => 'Weergave',
        'messages' => 'Berichten',
        'tickets' => 'Tickets',
    ],

    'header' => [
        'huddle' => 'Even praten',
        'schedule_huddle' => 'Huddle inplannen',
        'search' => 'Zoeken in :channel',
        'members' => 'Leden van dit kanaal',
        'connecting' => 'Realtime verbinding wordt opgezet…',
        'scheduled' => 'Ingeplande berichten',
        'settings' => 'Kanaalinstellingen',
        'shortcuts' => 'Snelkoppelingen',
        'favorite' => 'Bij favorieten zetten',
        'unfavorite' => 'Uit favorieten halen',
    ],

    'members' => [
        'panel' => 'Ledenlijst',
        'close' => 'Ledenlijst sluiten',
        'workspace' => 'Wie zit er in deze workspace',
    ],

    'posting_closed' => 'Alleen beheerders en de kanaalmaker kunnen berichten plaatsen in dit kanaal. Reageren en antwoorden in een thread kan wel.',

    'commands' => [
        'transfer' => 'Grote bestanden versturen via een downloadlink',
        'secret_ask' => 'Om een wachtwoord of sleutel vragen',
        'secret_send' => 'Een wachtwoord of sleutel naar iemand versturen',
        'poll' => 'Een vraag aan het kanaal stellen',
    ],
];
