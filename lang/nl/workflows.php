<?php

/*
 * The words a beheerder reads while building a workflow.
 *
 * Keyed by the trigger's or action's own key, which is the same string the
 * workflow stores — so a line here cannot drift from the thing it describes
 * without the class itself being renamed.
 *
 * The "provides" block is shared on purpose: half the triggers hand over a
 * message and a channel, and describing those once means the variable picker
 * says the same thing wherever somebody meets it.
 */

return [

    'triggers' => [

        'message-keyword' => [
            'label' => 'Als iemand een woord zegt',
            'description' => 'Loopt zodra er een bericht geplaatst wordt waar een van jouw woorden in staat.',
            'keywords' => [
                'label' => 'Woorden',
                'hint' => 'Eén per keer. Hoofdletters maken niet uit.',
            ],
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Leeg laten betekent: overal in deze workspace.',
            ],
        ],

        'channel-join' => [
            'label' => 'Als iemand lid wordt van een kanaal',
            'description' => 'Loopt zodra er iemand bij een kanaal komt. Ook als diegene er eerder al eens in zat.',
            'channel' => [
                'label' => 'Welk kanaal',
                'hint' => 'Leeg laten betekent: elk kanaal in deze workspace.',
            ],
        ],

        'reaction' => [
            'label' => 'Als iemand een emoji gebruikt',
            'description' => 'Loopt zodra deze emoji op een bericht gezet wordt. Weghalen en opnieuw zetten laat hem opnieuw lopen.',
            'emoji' => [
                'label' => 'Welke emoji',
                'hint' => 'Alleen deze ene zet de workflow in gang.',
            ],
            'channel' => [
                'label' => 'In welk kanaal',
                'hint' => 'Leeg laten betekent: overal in deze workspace.',
            ],
        ],

        'link' => [
            'label' => 'Als iemand hem zelf start',
            'description' => 'Verschijnt in het berichtmenu. Wie hem daar kiest, start hem met dat bericht erbij.',
        ],

        'webhook' => [
            'label' => 'Als er iets binnenkomt op een URL',
            'description' => 'Je krijgt een geheime URL. Alles wat daarnaartoe gestuurd wordt, zet de workflow in gang.',
        ],

        'schedule' => [
            'label' => 'Op een vast moment',
            'description' => 'Loopt vanzelf, op het ritme dat je kiest. De tijd is jouw eigen klok, dus de tijdzone die in je profiel staat.',
            'cadence' => [
                'label' => 'Hoe vaak',
                'hourly' => 'Elk uur',
                'daily' => 'Elke dag',
                'weekly' => 'Elke week',
            ],
            'time' => [
                'label' => 'Hoe laat',
                'hint' => 'Als 09:00. Bij elk uur hoef je dit niet in te vullen.',
            ],
            'weekday' => [
                'label' => 'Op welke dag',
                'hint' => 'Alleen nodig als het elke week is.',
            ],
        ],
    ],

    /*
     * What a run says about itself when it stopped early. Read on the
     * run-screen by whoever is wondering why nothing happened, so each of these
     * has to name the thing they can go and change.
     */
    'actions' => [

        'fields' => [
            'channel' => 'Welk kanaal',
            'person' => 'Wie',
            'body' => 'Wat er komt te staan',
            'body_hint' => 'Je kunt hier gegevens uit de trigger in zetten.',
            'message' => 'Welk bericht',
            'message_hint' => 'Leeg laten betekent: het bericht waar de trigger over ging.',
            'added' => 'Of er echt iemand is toegevoegd',
            'thread' => [
                'id' => 'De thread waar het antwoord in kwam',
            ],
            'emoji' => 'Welke emoji',
            'channel_name' => 'Naam van het kanaal',
            'channel_name_hint' => 'Mag gegevens uit de trigger bevatten, bijvoorbeeld de naam van wie het aanvroeg.',
            'channel_type' => 'Wie mag erbij',
            'topic' => 'Onderwerp',
        ],

        'send-channel-message' => [
            'label' => 'Bericht in een kanaal',
            'description' => 'Plaatst een bericht onder de naam van deze workflow, herkenbaar als bot.',
        ],
        'send-direct-message' => [
            'label' => 'Bericht aan een persoon',
            'description' => 'Stuurt een DM. Het gesprek loopt via de eigenaar van deze workflow.',
        ],
        'reply-in-thread' => [
            'label' => 'Antwoord in een thread',
            'description' => 'Hangt een antwoord onder een bericht in plaats van ernaast.',
        ],
        'add-reaction' => [
            'label' => 'Emoji op een bericht zetten',
            'description' => 'Reageert namens de eigenaar van deze workflow.',
        ],
        'remove-reaction' => [
            'label' => 'Emoji weghalen',
            'description' => 'Haalt alleen de reactie van de eigenaar van deze workflow weg.',
        ],
        'pin-message' => [
            'label' => 'Bericht vastzetten',
            'description' => 'Zet een bericht bovenaan het kanaal.',
        ],
        'unpin-message' => [
            'label' => 'Bericht losmaken',
            'description' => 'Haalt een bericht weer van de vastgezette lijst af.',
        ],
        'create-channel' => [
            'label' => 'Kanaal aanmaken',
            'description' => 'Opent een nieuw kanaal. De volgende stappen kunnen er meteen bij.',
            'public' => 'Iedereen in de workspace',
            'private' => 'Alleen wie je toevoegt',
        ],
        'add-channel-members' => [
            'label' => 'Iemand aan een kanaal toevoegen',
            'description' => 'Zet één persoon in een kanaal. Was diegene er al, dan gebeurt er niets.',
        ],
        'get-channel-info' => [
            'label' => 'Kanaalgegevens ophalen',
            'description' => 'Verandert niets, maar zet naam, onderwerp en ledenaantal klaar voor een volgende stap.',
        ],
        'archive-channel' => [
            'label' => 'Kanaal archiveren',
            'description' => 'Sluit een kanaal. Alles blijft leesbaar, niemand kan er nog posten.',
        ],
        'unarchive-channel' => [
            'label' => 'Kanaal weer openen',
            'description' => 'Haalt een kanaal uit het archief.',
        ],
        'http-request' => [
            'label' => 'HTTP-verzoek doen',
            'description' => 'Vraagt iets aan een ander systeem en onthoudt het antwoord, zodat een volgende stap ermee verder kan.',
            'method' => [
                'label' => 'Wat voor verzoek',
            ],
            'url' => [
                'label' => 'Naar welke URL',
                'hint' => 'Moet met https:// beginnen en buiten dit netwerk staan. Je mag er gegevens uit eerdere stappen in zetten.',
            ],
            'headers' => [
                'label' => 'Headers',
                'hint' => 'Eén per regel, als "Authorization: Bearer abc". Hier zet je meestal je sleutel.',
            ],
            'body' => [
                'label' => 'Wat je meestuurt',
                'hint' => 'Meestal JSON. Blijft leeg bij een GET. Gegevens uit eerdere stappen mogen erin.',
            ],
        ],
        'delay' => [
            'label' => 'Wachten',
            'description' => 'Zet de workflow stil en pakt hem later weer op.',
            'minutes' => [
                'label' => 'Hoeveel minuten',
                'hint' => 'Een uur is 60, een dag 1440. Maximaal vier weken.',
            ],
        ],
    ],

    /*
     * What goes wrong, in words the person who wrote the workflow can act on.
     * Every one of these ends up on the run screen, so "kanaal niet gevonden"
     * has to be a complete answer there rather than the start of a hunt.
     */
    'errors' => [
        'no_channel_chosen' => 'Deze stap heeft geen kanaal gekregen.',
        'channel_not_found' => 'Dat kanaal bestaat niet meer, of de eigenaar van deze workflow mag er niet bij.',
        'no_message' => 'Deze stap gaat over een bericht, maar er is er geen.',
        'message_not_found' => 'Dat bericht bestaat niet meer.',
        'no_person_chosen' => 'Deze stap heeft geen persoon gekregen.',
        'person_not_found' => 'Die persoon zit niet meer in deze workspace.',
        'no_owner' => 'Deze workflow heeft geen eigenaar meer.',
        'may_not_post' => 'De eigenaar van deze workflow mag niet posten in #:channel.',
        'may_not_dm' => 'De eigenaar van deze workflow mag deze persoon geen bericht sturen.',
        'may_not_pin' => 'De eigenaar van deze workflow mag hier niets vastzetten.',
        'may_not_archive' => 'De eigenaar van deze workflow mag dit kanaal niet archiveren.',
        'may_not_add_members' => 'De eigenaar van deze workflow mag hier niemand toevoegen.',
        'may_not_create_channel' => 'De eigenaar van deze workflow mag geen kanalen aanmaken.',
        'no_channel_name' => 'Er is geen naam voor het kanaal overgebleven.',
        'empty_message' => 'Er bleef geen tekst over om te versturen.',
        'url_unreadable' => 'Dat is geen adres waar Pcom iets mee kan.',
        'url_scheme' => 'Alleen http:// en https:// kunnen opgevraagd worden.',
        'url_not_public' => 'Dit adres ligt binnen het eigen netwerk van de server. Dat mag een workflow niet opvragen.',
        'url_unknown_host' => 'Dat adres bestaat niet, of het antwoordt op dit moment niet.',
        'http_method' => 'Dat soort verzoek kent Pcom niet.',
        'http_unreachable' => 'Er kwam geen antwoord. Het adres deed er te lang over of is niet bereikbaar.',
        'delay_too_short' => 'Wachten doe je minstens een minuut.',
        'delay_too_long' => 'Langer dan vier weken wachten kan niet.',
    ],

    'webhook' => [
        'unknown' => 'Onbekende workflow.',
    ],

    /* What the builder screen says back after a change. */
    'screen' => [
        'created' => 'Workflow aangemaakt. Zet hem aan zodra de stappen kloppen.',
        'saved' => 'Workflow opgeslagen.',
        'deleted' => 'Workflow verwijderd.',
        'too_many' => 'Meer dan :count workflows per workspace is te veel om te overzien.',
        'no_steps' => 'Deze workflow heeft nog geen stappen, dus er valt niets aan te zetten.',
        'too_many_steps' => 'Meer dan :count stappen in één workflow is te veel om te volgen.',
    ],

    /* What somebody sees after starting one from the message menu. */
    'link' => [
        'started' => '":name" is gestart.',
        'refused' => 'Deze workflow kon nu niet starten.',
    ],

    'run' => [
        'no_longer_allowed' => 'Deze workflow staat uit of heeft geen eigenaar meer, dus de rest is niet uitgevoerd.',
        'unknown_action' => 'Deze stap doet iets (:action) wat Pcom niet meer kent.',
        'step_failed' => 'Deze stap ging mis.',
        'went_round_in_circles' => 'Deze workflow loopt in een kring en is gestopt.',
    ],

    /*
     * How a value reads once it is part of a sentence. Only the two that have
     * no natural wording of their own: everything else is already text by the
     * time a step sees it.
     */
    'value' => [
        'yes' => 'ja',
        'no' => 'nee',
        // What is left where an answer was cut off, so half a sentence does
        // not read as the whole of one.
        'truncated' => '… (afgekapt)',
    ],

    'weekdays' => [
        1 => 'Maandag',
        2 => 'Dinsdag',
        3 => 'Woensdag',
        4 => 'Donderdag',
        5 => 'Vrijdag',
        6 => 'Zaterdag',
        7 => 'Zondag',
    ],

    'provides' => [
        'http' => [
            'status' => 'De statuscode van het antwoord',
            'ok' => 'Of het verzoek gelukt is',
            'json' => 'Het antwoord (JSON)',
            'body' => 'Het antwoord als tekst',
        ],
        'message' => [
            'id' => 'Het bericht',
            'text' => 'Wat er in het bericht staat',
        ],
        'channel' => [
            'topic' => 'Het onderwerp van het kanaal',
            'members' => 'Hoeveel leden het kanaal heeft',
            'archived' => 'Of het kanaal gearchiveerd is',
            'id' => 'Het kanaal',
            'name' => 'De naam van het kanaal',
        ],
        'user' => [
            'id' => 'Wie het deed',
            'name' => 'De naam van wie het deed',
        ],
        'reactor' => [
            'id' => 'Wie de emoji zette',
            'name' => 'De naam van wie de emoji zette',
        ],
        'starter' => [
            'id' => 'Wie de workflow startte',
            'name' => 'De naam van wie de workflow startte',
        ],
        'author' => [
            'id' => 'Wie het bericht schreef',
            'name' => 'De naam van wie het bericht schreef',
        ],
        'moment' => [
            'date' => 'De datum van vandaag',
            'time' => 'Hoe laat het is',
        ],
        'emoji' => 'De emoji die gebruikt werd',
        'keyword' => 'Het woord dat gevonden werd',
        'payload' => 'Alles wat er binnenkwam',
    ],
];
