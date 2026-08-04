<?php

/*
 * The panels that open beside a conversation, and the pages and dialogs that
 * belong to them: tickets, what is still scheduled, what is pinned up, the
 * groups you file channels under, and the status you hang on yourself.
 *
 * Grouped by the panel a reader is looking at rather than by the component that
 * draws it — the same wording turns up in a header, a button and a tooltip, and
 * keying by component would make every refactor a rename.
 *
 * Statuses and priorities are deliberately absent: those are enum cases, and
 * enums.php already holds the words for them. A second wording here would drift
 * from the one the server sends in its mails within a week.
 */

return [
    'save' => 'Opslaan',
    'cancel' => 'Annuleren',

    'tickets' => [
        'title' => 'Tickets',
        'intro' => 'Alles uit de kanalen die je kunt zien',
        'new' => 'Nieuw ticket',
        'outstanding' => 'Openstaand',
        'everything' => 'Alles',
        'priority_filter' => 'Prioriteit',
        'channel_filter' => 'Kanaal',
        'any' => ':label: alle',
        'no_channels' => 'Nog geen enkel kanaal houdt tickets bij. Zet tickets aan in de instellingen van een kanaal.',
        'none' => 'Geen tickets die hieraan voldoen.',
        'open_in' => 'Open #:number in #:channel',
        'open_in_its_channel' => 'Open #:number in het kanaal',
    ],

    'ticket' => [
        'unknown' => 'Onbekend',
        'close' => 'Ticket sluiten',
        'title_field' => 'Titel',
        'edit_title' => 'Titel aanpassen',
        'body_field' => 'Omschrijving',
        'edit_body' => 'Omschrijving aanpassen',
        'escape_cancels' => 'annuleert',
        'status' => 'Status',
        'priority' => 'Prioriteit',
        'assignee' => 'Toegewezen aan',
        'nobody' => 'Niemand',
        'solved' => 'Dit is opgelost',
        'not_solved' => 'Toch niet opgelost',
        'from_message' => 'Uit een bericht van :author',
        'source_deleted' => 'Dit bericht is verwijderd',
        'guest' => 'gast',
        'edited' => 'bewerkt',
        'comment_withdrawn' => 'Deze reactie is ingetrokken',
        'comment_placeholder' => 'Reageer op dit ticket',

        /*
         * One line of history, put into words at reading time rather than at
         * writing time: the event holds what changed, and a sentence baked in
         * years ago would still be the old wording — in the old language.
         */
        'event' => [
            'system' => 'Systeem',
            'created' => ':who maakte dit ticket aan',
            'status_changed' => ':who zette de status op :status',
            'priority_changed' => ':who zette de prioriteit op :priority',
            'assigned' => ':who wees dit ticket toe',
            'unassigned' => ':who haalde de toewijzing weg',
            'due_date_set' => ':who zette een streefdatum',
            'due_date_cleared' => ':who haalde de streefdatum weg',
            'other' => ':who wijzigde iets',
        ],
    ],

    'scheduled' => [
        'title' => 'Ingepland',
        'waiting' => '{1}1 bericht wacht nog|[2,*]:count berichten wachten nog',
        'close' => 'Sluiten',
        'empty' => 'Niets staat klaar voor dit kanaal.',
        'failed' => 'Dit bericht kon niet verstuurd worden.',
        'body_field' => 'Bericht',
        'send_at_field' => 'Versturen op',
        'withdraw' => 'Intrekken',
        'confirm_title' => 'Dit ingeplande bericht intrekken?',
        'confirm_body' => 'Het gaat niet meer uit en de tekst is weg. Het is nog nergens gezegd, dus er blijft niets van over.',
    ],

    'section' => [
        'file' => 'In een groep zetten',
        'filed_in' => 'In de groep :name',
        'yours_alone' => 'Jouw groepen — alleen jij ziet ze',
        'new_menu' => 'Nieuwe groep…',
        'new' => 'Nieuwe groep',
        'intro' => 'Een kop in jouw zijbalk. Je collega\'s zien er niets van — anders dan een label op het kanaal, dat wél voor iedereen geldt.',
        'name_field' => 'Naam',
        'name_placeholder' => 'Bijvoorbeeld: Klanten',
        'create' => 'Groep maken',
    ],

    'pinned' => [
        'title' => 'Vastgepind',
        'by' => 'Vastgepind door :who · :moment',
        'at' => 'Vastgepind op :moment',
        'count' => '{1}1 vastgepind bericht|[2,*]:count vastgepinde berichten',
        'view' => 'Bekijken',
        'messages' => '{1}1 bericht|[2,*]:count berichten',
        'close' => 'Vastgepinde berichten sluiten',
        'empty' => 'Er is niets vastgepind in dit kanaal.',
        'jump' => 'Naar bericht',
        'unpin' => 'Losmaken',
        'unreachable' => 'Dit bericht staat buiten het geladen deel van het kanaal. Scroll omhoog om het op te halen.',
    ],

    'status' => [
        'title' => 'Je status',
        'intro' => 'Wat je aan het doen bent, en of je gestoord mag worden.',
        'field' => 'Status',
        'emoji_field' => 'Emoji',
        'placeholder' => 'Waar ben je mee bezig?',
        'availability' => 'Beschikbaarheid',
        'clear' => 'Status wissen',

        /*
         * What most people are about to type anyway, offered beside their own
         * recent statuses. Keyed by what they mean rather than by their wording,
         * so the English list is a list of the same seven situations and not a
         * translation of seven Dutch sentences.
         */
        'suggestion' => [
            'meeting' => 'In vergadering',
            'lunch' => 'Lunchpauze',
            'focus' => 'Aan het focussen',
            'home' => 'Werkt thuis',
            'commuting' => 'Onderweg',
            'sick' => 'Ziek',
            'holiday' => 'Op vakantie',
        ],
    ],
];
