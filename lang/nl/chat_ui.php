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
        'add' => 'Knop toevoegen',
        'move_up' => ':label naar voren',
        'move_down' => ':label naar achteren',
        'open' => 'Openen',
        'open_named' => ':label openen',
        'remove' => ':label verwijderen',
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
];
