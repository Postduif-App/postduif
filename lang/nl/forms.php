<?php

/*
 * Alles wat op het scherm komt te staan rond formulieren: de bouwer, de
 * invulpagina's, de kaart in een kanaal en het bericht dat de bot achteraf
 * stuurt.
 *
 * Dat laatste blok — 'dm' — gaat naar degene die het formulier maakte en volgt
 * dus diens taal, niet die van de invuller. Zie SendFormAnswers, dat de locale
 * omzet voordat het de zinnen opbouwt.
 */

return [
    'types' => [
        'short-text' => 'Korte tekst',
        'long-text' => 'Lange tekst',
        'choice' => 'Eén keuze',
        'multiple-choice' => 'Meerdere keuzes',
        'number' => 'Getal',
        'date' => 'Datum',
        'boolean' => 'Ja of nee',
    ],

    'answers' => [
        'empty' => '—',
        'yes' => 'Ja',
        'no' => 'Nee',
        'anonymous' => 'Iemand van buiten',
        'via_link' => 'Via de gedeelde link',
    ],

    'dm' => [
        'intro' => ':name vulde ":form" in.',
        'anonymous_intro' => 'Er kwam een ingevuld ":form" binnen via de gedeelde link.',
        'line' => '**:question**: :answer',
        'answers' => 'Alle antwoorden staan ook bij het formulier zelf.',
    ],

    /*
     * De kaart in een kanaal. Wat hier niet staat is net zo belangrijk als wat
     * er wel staat: geen aantal inzendingen en geen "jij hebt dit al ingevuld".
     * De kaart wordt uit één payload naar het hele kanaal gestuurd — zie
     * PresentMessage::formCard — en kan die dingen dus niet weten zonder ze aan
     * iedereen te vertellen.
     */
    'card' => [
        'fill' => 'Invullen',
        'closed' => 'Dit formulier is gesloten',
        'expired' => 'De termijn is verstreken',
        'empty' => 'Dit formulier heeft nog geen vragen',
        'questions' => ':count vraag|:count vragen',
    ],

    'screen' => [
        'title' => 'Formulieren',
        'description' => 'Vragenlijsten die je in een kanaal zet of als link deelt. De antwoorden komen bij jou terug.',
        'new' => 'Nieuw formulier',
        'none' => 'Er is hier nog geen formulier gemaakt.',
        'open' => 'Open',
        'closed' => 'Gesloten',
        'shared' => 'Gedeeld',
        'submissions' => 'Inzendingen',
        'edit' => 'Bewerken',
        'answers' => 'Antwoorden',
        'delete' => 'Verwijderen',
        'delete_confirm' => 'Dit formulier en alles wat er is ingevuld gaan weg. Doorgaan?',
        'form_title' => 'Naam van het formulier',
        'form_description' => 'Uitleg vooraf',
        'form_description_hint' => 'Wat iemand leest voordat hij begint. Mag leeg blijven.',
        'closes_at' => 'Sluit op',
        'closes_at_hint' => 'Leeg laten betekent: net zolang tot je het zelf stopt.',
        'allows_multiple' => 'Mag meerdere keren ingevuld worden',
        'notify_channel' => 'Kanaal voor anonieme inzendingen',
        'notify_channel_hint' => 'Iemand die via de link invult heeft geen gesprek met jou. Kies hier waar die antwoorden dan binnenkomen — leeg laten betekent: alleen op het antwoordenscherm.',
        'fields' => 'Vragen',
        'field_label' => 'De vraag',
        'field_hint' => 'Toelichting',
        'field_type' => 'Soort antwoord',
        'field_required' => 'Verplicht',
        'field_options' => 'Keuzes',
        'field_options_hint' => 'Eén per regel.',
        'field_key' => 'Verwijzing voor workflows',
        'add_field' => 'Vraag toevoegen',
        'remove_field' => 'Weg',
        'move_up' => 'Omhoog',
        'move_down' => 'Omlaag',
        'no_fields' => 'Nog geen vragen. Voeg er één toe, anders valt er niets in te vullen.',
        'save' => 'Opslaan',
        'close_form' => 'Sluiten',
        'reopen_form' => 'Weer openzetten',
        'share' => 'Deelbare link maken',
        'reshare' => 'Nieuwe link maken',
        'unshare' => 'Link intrekken',
        'share_hint' => 'Met deze link kan iedereen die hem heeft dit formulier invullen, ook zonder account. Een nieuwe link maken zet de oude uit.',
        'copy' => 'Kopiëren',
        'copied' => 'Gekopieerd',
        'post' => 'In een kanaal zetten',
        'post_channel' => 'Welk kanaal',
    ],

    'fill' => [
        'title' => 'Formulier invullen',
        'send' => 'Versturen',
        'sent' => 'Verstuurd. Bedankt.',
        'closed' => 'Dit formulier neemt niets meer aan.',
        'already' => 'Je hebt dit formulier al ingevuld.',
        'empty' => 'Er staan nog geen vragen in dit formulier.',
        'author' => 'Van :name',
        'expired' => 'De termijn voor dit formulier is verstreken.',
        'closes_on' => 'Sluit op :date',
        'back' => 'Terug naar de chat',
        'anonymous_notice' => 'Je vult dit in via een gedeelde link. Je naam wordt niet meegestuurd.',
        'named_notice' => ':name ziet wie dit invulde.',
    ],

    'answers_screen' => [
        'title' => 'Antwoorden op :form',
        'none' => 'Er is nog niets ingevuld.',
        'when' => 'Wanneer',
        'who' => 'Wie',
        'export' => 'Downloaden als CSV',
        'back' => 'Terug naar formulieren',
        'count' => ':count inzending|:count inzendingen',
    ],

    'errors' => [
        'too_many' => 'Er passen er niet meer dan :count in één workspace.',
        'closed' => 'Dit formulier is gesloten.',
        'already_submitted' => 'Je hebt dit formulier al ingevuld.',
        'no_fields' => 'Een formulier zonder vragen kan niet ingevuld worden.',
        'unknown_link' => 'Deze link doet het niet meer.',
        'options_required' => 'Een keuzevraag heeft minstens twee keuzes nodig.',
        'options_unexpected' => 'Bij dit soort vraag horen geen keuzes.',
    ],
];
