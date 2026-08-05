<?php

/*
 * Wat de app terugzegt na een handeling: de regel boven een formulier of de
 * toast rechtsonder.
 *
 * Gegroepeerd naar waar het over gaat en niet naar welke controller het
 * verstuurt, want dezelfde zin komt soms uit twee plekken — een bericht
 * inplannen gebeurt zowel met een toast als met een gewone terugmelding.
 *
 * Zinnen met een naam erin staan hier als één regel met :name en niet als
 * losse stukjes. Aan elkaar geplakte tekst is in het Nederlands nog te volgen,
 * maar in een taal met een andere woordvolgorde niet meer te maken.
 */
return [
    'channel' => [
        'saved' => 'Kanaalinstellingen opgeslagen.',
        'deleted' => '#:name is verwijderd.',
        'unmuted' => 'Meldingen voor dit kanaal staan weer aan.',
        'muted' => 'Meldingen voor dit kanaal staan uit.',
        'muted_until' => 'Meldingen voor dit kanaal staan uit tot :time.',
        'forwarded' => 'Doorgestuurd naar #:name.',
        'archived' => '#:name is gearchiveerd.',
        'reopened' => '#:name is weer open.',

        /*
         * Alle drie de gevallen als hele zin, ook de nul. "Niemand toegevoegd"
         * is niet dezelfde zin met een ander getal erin: er is niets gebeurd,
         * en dat hoort te lezen als iets anders dan een telling.
         */
        'members_added' => '{0}Niemand toegevoegd.|{1}1 lid toegevoegd.|[2,*]:count leden toegevoegd.',
        'member_removed' => ':name is uit het kanaal verwijderd.',
    ],

    'message' => [
        'scheduled' => 'Bericht ingepland.',
        'updated' => 'Bericht aangepast.',
        'withdrawn' => 'Bericht ingetrokken.',
    ],

    'poll' => [
        'closed' => 'Poll gesloten.',
        'reopened' => 'Poll heropend.',
    ],

    'transfer' => [
        'created' => 'Bestanden klaargezet. De link staat in de lijst.',
        'withdrawn' => 'Verzending ingetrokken.',
        'link_withdrawn' => 'Link voor :email ingetrokken.',
    ],

    'secret' => [
        'withdrawn' => 'Verzoek ingetrokken.',
        'filled' => '{1}Bedankt, de waarde is opgeslagen. Je kunt hem niet meer bekijken.|[2,*]Bedankt, :count waarden zijn opgeslagen. Je kunt ze niet meer bekijken.',
    ],

    'invitation' => [
        'sent' => 'Uitnodiging verstuurd naar :email.',
        'resent' => 'Uitnodiging opnieuw verstuurd naar :email.',
        'withdrawn' => 'Uitnodiging voor :email ingetrokken.',
        'link_created' => 'Uitnodigingslink aangemaakt.',
        'link_withdrawn' => 'Uitnodigingslink ingetrokken.',
        // Na het aannemen van een uitnodiging of het volgen van een link.
        'welcome' => 'Welkom bij :workspace.',
    ],

    'member' => [
        'channels_updated' => 'De kanalen van :name zijn bijgewerkt.',
        // Apart, omdat "bijgewerkt" bij nul wijzigingen suggereert dat er iets
        // gebeurd is waar iemand naar kan zoeken.
        'channels_unchanged' => 'Er is niets veranderd aan de kanalen van :name.',

        /*
         * Elke tak een hele zin, ook al staat het eerste stuk er drie keer.
         * Het alternatief is een rolwissel-zin met een tweede zin eraan
         * geplakt, en aan elkaar geplakte tekst is in een taal met een andere
         * woordvolgorde niet meer te maken.
         */
        'role_changed' => '{0}:name is nu :role.|{1}:name is nu :role. Eén openbaar kanaal is daarbij losgekoppeld.|[2,*]:name is nu :role. :count openbare kanalen zijn daarbij losgekoppeld.',
        'removed' => ':name is uit de workspace verwijderd.',
    ],

    'settings' => [
        'saved' => 'Instellingen opgeslagen.',
        'permissions_saved' => 'Rechten opgeslagen.',
        'notifications_saved' => 'Notificaties opgeslagen.',
        'theme_saved' => 'Thema opgeslagen.',
        'avatar_saved' => 'Foto opgeslagen.',
        'avatar_removed' => 'Foto verwijderd.',
        'logo_saved' => 'Logo opgeslagen.',
        'logo_removed' => 'Logo verwijderd.',
    ],

    'rule' => [
        'added' => 'Regel toegevoegd.',
        'updated' => 'Regel bijgewerkt.',
        'removed' => 'Regel verwijderd.',
    ],

    'role' => [
        'created' => 'De rol :name is aangemaakt.',
        'saved' => 'De rol :name is opgeslagen.',
        'deleted' => 'De rol :name is verwijderd.',
    ],
];
