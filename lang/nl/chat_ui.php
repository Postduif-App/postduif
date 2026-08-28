<?php

/*
 * The smaller pieces the chat is assembled from: the cards under a message, the
 * badges beside a name, the panels and fields that hang off a channel.
 *
 * Grouped by the thing a reader is looking at rather than by the component that
 * draws it — a card is the same card whether the feed or the conversation puts
 * it on screen, and keying by component would make every refactor a rename.
 *
 * What already had a word elsewhere is not repeated here: a deleted message is
 * messages.deleted, a ticket's status is an enum case, and the thread wording
 * lives in sidebar.php. Only what had nowhere to live is below.
 */

return [
    'contract' => [
        'signed' => ':done van de :total getekend',
        'draft' => 'nog niet verstuurd',
        'open' => 'wacht op handtekeningen',
        'until' => 'tekenen kan tot :date',
        'completed' => 'afgerond',
        'cancelled' => 'ingetrokken',
        'expired' => 'verlopen',
    ],
    'guest' => [
        'label' => 'Gast',
        'hint' => 'Iemand van buiten, alleen in de kanalen waar ze voor zijn uitgenodigd',
    ],

    'join' => [
        // Split around the channel name, which is set apart in the sentence.
        'lead' => 'Je leest mee in',
        'tail' => '. Word lid om te kunnen reageren.',
        'submit' => 'Word lid',
    ],

    'feed' => [
        'empty' => 'Nog niets geplaatst.',
        'reply' => 'Reageren',
        'replies' => '{1}1 reactie|[2,*]:count reacties',
    ],

    'thread' => [
        'heading' => 'Thread',
        'close' => 'Thread sluiten',
        'replies_closed' => 'Reageren staat uit in dit kanaal',
        'join_first' => 'Word lid van dit kanaal om te reageren',
    ],

    'reactions' => [
        'pick' => 'Reageer met een emoji',
        'search' => 'Zoek een andere emoji',
        'dialog_title' => 'Emoji kiezen',
        'dialog_description' => 'Zoek een emoji',
        'placeholder' => 'Zoek een emoji…',
        'none' => 'Geen emoji gevonden.',
        'custom' => 'Van deze workspace',
    ],

    'code' => [
        'copy' => 'Kopieer',
        'copied' => 'Gekopieerd',
    ],

    'members' => [
        'heading' => 'Leden',
        'online' => 'Nu online',
        'you' => 'jij',
    ],

    'mute' => [
        'action' => 'Meldingen dempen',
        'until' => 'Gedempt tot :moment',
        'until_forever' => 'Gedempt totdat je het weer aanzet',
        'unmute' => 'Meldingen weer aanzetten',
        'heading' => 'Dit kanaal stil houden',
        'instant_active' => 'Meteen een melding voor alles',
        'instant_heading' => 'Meldingen voor dit kanaal',
        'instant_all' => 'Alles, meteen',
        'instant_default' => 'Standaard (na afwezigheid)',

        /*
         * How long quiet lasts, said in the way somebody decides it. Keyed by
         * the situation rather than by the number of hours, so the English list
         * is the same five situations and not five translated durations.
         */
        'duration' => [
            'hour' => 'Een uur',
            'workday' => 'De rest van de werkdag',
            'tomorrow' => 'Tot morgen',
            'week' => 'Een week',
            'forever' => 'Tot ik het weer aanzet',
        ],
    ],

    'tags' => [
        'label' => 'Tags',
        'placeholder' => 'Typ een tag en druk op Enter',
        'remove' => ':tag weghalen',
        'in_use' => 'Al in gebruik:',
        'hint' => 'Tags horen bij de workspace, niet bij dit kanaal: dezelfde tag kan aan meerdere kanalen hangen. Een tag die nergens meer op zit verdwijnt vanzelf.',
    ],

    'links' => [
        'explanation' => 'Verschijnen in een balk boven het gesprek, voor iedereen die het kanaal kan zien — gasten dus ook.',
        'empty' => 'Nog geen knoppen. Voeg er hieronder een toe.',
        'new' => 'Nieuwe knop',
        'address' => 'Adres',
        'target' => 'Wat de knop doet',
        'target_url' => 'Een adres openen',
        // Kan alleen als de workflow net weg is en de pagina nog niet ververst.
        'workflow_gone' => 'Deze workflow bestaat niet meer',
        'add' => 'Knop toevoegen',
        'move_up' => ':label naar voren',
        'move_down' => ':label naar achteren',
        'open' => 'Openen',
        'open_named' => ':label openen',
        'remove' => ':label verwijderen',
    ],

    /*
     * De regels die maar een iemand ziet: het antwoord op iets dat je zelf net
     * deed. Bewust kort — het staat onder het gesprek, niet erin.
     */
    'notice' => [
        'only_you' => 'Alleen jij ziet dit',
        'dismiss' => 'Wegklikken',
    ],

    /* De balk boven het gesprek als er gepraat wordt, of gepraat kan worden. */
    'huddle' => [
        /*
         * Een huddle inplannen. Bewust "inplannen" en niet "aanmaken": er
         * gebeurt op dat moment nog niets, en het kanaal merkt er pas iets van
         * als het zover is.
         */
        'schedule' => [
            'title' => 'Huddle inplannen',
            'description' => 'Zet een gesprek in de agenda van dit kanaal. Het kanaal krijgt er bericht van zodra het zover is — niet nu.',
            'upcoming' => 'Staat al gepland',
            'cancel' => 'Afzeggen',
            'title_label' => 'Waar gaat het over',
            'title_placeholder' => 'Sprintplanning',
            'when_label' => 'Wanneer',
            'duration_label' => 'Hoe lang',
            'minutes' => ':count min',
            'invitees_label' => 'Wie vraag je erbij',
            'invitees_none' => 'Niemand aangevinkt: dan is het voor het hele kanaal.',
            'invitees_hint' => 'Wie je aanvinkt wordt bij naam genoemd in de aankondiging.',
            'save' => 'Inplannen',
        ],

        'talking' => '{1}is aan het praten|[2,*]zijn aan het praten',
        'alone' => 'Je wacht tot er iemand bij komt.',
        'starting' => 'Bezig met verbinden…',
        'start' => 'Huddle starten',
        'join' => 'Meedoen',
        'leave' => 'Weggaan',
        'mute' => 'Microfoon uit',
        'unmute' => 'Microfoon aan',
        'no_microphone' => 'Geen toegang tot de microfoon.',
        'no_camera' => 'Geen toegang tot de camera.',
        'camera_on' => 'Camera aan',
        'camera_off' => 'Camera uit',
        'you' => 'Jij',
        'expand' => 'Groot',
        'pick_microphone' => 'Kies een microfoon',
        'pick_camera' => 'Kies een camera',
        'unnamed_device' => 'Naamloos apparaat',
        'shrink' => 'Kleiner',
        'too_many_cameras' => 'Met meer dan :count camera\'s wordt het zwaar voor de browsers.',
        'full' => 'Een huddle gaat tot :count mensen.',

        /*
         * Opnemen. De melding staat er bewust in de tegenwoordige tijd en met
         * een naam erbij: "er wordt opgenomen" laat in het midden door wie, en
         * dat is juist het enige wat iemand op dat moment wil weten.
         *
         * "Je neemt op" is een aparte zin en niet dezelfde met je eigen naam
         * erin — je eigen naam teruglezen terwijl je de knop net ingedrukt hebt
         * leest als een mededeling over iemand anders.
         */
        'record' => 'Opnemen',
        'record_stop' => 'Opname stoppen',
        'recording_by' => ':name neemt dit gesprek op',
        'recording_you' => 'Je neemt dit gesprek op',
        'recording_saving' => 'Opname wordt bewaard…',
        'recording_failed' => 'De opname is niet gelukt.',
        'record_unsupported' => 'Deze browser kan niet opnemen.',
    ],

    'payload' => [
        'too_large' => 'Het laatste bericht was te groot om te bewaren.',
        'show' => 'Bekijk wat er laatst binnenkwam',
        'hide' => 'Verberg wat er laatst binnenkwam',
        'use' => 'Gebruik :path',
    ],

    'poll' => [
        'closed' => 'Gesloten',
        'expired' => 'Verlopen',
        'no_votes' => 'Nog niemand heeft gestemd',
        'votes' => '{1}1 persoon heeft gestemd|[2,*]:count mensen hebben gestemd',
        'multiple' => 'meerdere antwoorden mogen',
        'state_closed' => 'gesloten',
        'state_expired' => 'verlopen',
        'public_note' => 'iedereen ziet wat je stemt',
        'reopen' => 'Poll heropenen',
        'close' => 'Poll sluiten',
    ],

    'secret' => [
        'filled' => ':done van :total ingevuld',
        'complete' => 'compleet',
        'until' => 'tot :date',
        'expired' => 'verlopen',
        'revoked' => 'ingetrokken',
    ],

    'sent_secret' => [
        'for' => 'Voor :name',
        'revealed' => 'opgehaald',
        'expired' => 'niet meer beschikbaar',
        'expires' => 'vervalt :date',
        'withdraw' => 'Intrekken',
        'withdraw_confirm' => 'Dit geheim intrekken?',
    ],

    'board' => [
        'editing' => 'Bericht aanpassen',
        'pin' => 'Vastzetten',
        'unpin' => 'Losmaken',
        'edit' => 'Aanpassen',
        'delete' => 'Weghalen',
        'delete_confirm' => 'Dit bericht van het prikbord halen?',
        'back' => 'Terug naar het prikbord',
        'fullscreen' => 'Volledig scherm',
        'close' => 'Sluiten',
        'edited' => 'aangepast',
        'author_gone' => 'Oud-collega',
        'no_comments' => 'Nog geen reacties',
        'comments' => '{1}1 reactie|[2,*]:count reacties',
        'comment_field' => 'Reactie',
        'comment_edit' => 'Reactie aanpassen',
        'comment_delete' => 'Reactie weghalen',
        'comment_placeholder' => 'Reageer op dit bericht…',
    ],

    'tickets' => [
        'none_outstanding' => 'Niets meer openstaand.',
        'none_with_status' => 'Geen tickets met deze status.',
    ],

    /*
     * De balk boven de app als de socket weg is. Geen woord over websockets of
     * Reverb: wat je merkt is dat er niets meer binnenkomt, en dat is wat er
     * staat.
     */
    'connection' => [
        'offline' => 'Geen verbinding met de server — nieuwe berichten komen nu niet binnen.',
        'reload' => 'Opnieuw laden',
    ],
];
