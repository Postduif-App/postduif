<?php

/*
 * Everything the three channel dialogs say: making one, changing one, and
 * deciding who is in it.
 *
 * Grouped by what the reader is looking at rather than by which dialog draws
 * it — the visibility question is asked twice, once while creating and once
 * while editing, and it is the same question both times.
 */

return [
    /*
     * The channel every workspace starts with. Slugged on the way in, so
     * this is a name rather than an address.
     */
    'home' => [
        'name' => 'algemeen',
        'topic' => 'Alles wat niet ergens anders past',
    ],

    'actions' => [
        'cancel' => 'Annuleren',
        'save' => 'Opslaan',
        'create' => 'Aanmaken',
        'archive' => 'Archiveren',
        'remove' => 'Verwijderen',
    ],

    'fields' => [
        'name' => 'Naam',
        'topic' => 'Onderwerp',
        'topic_optional' => '(optioneel)',
        'topic_placeholder' => 'Waar gaat dit kanaal over?',
    ],

    'visibility' => [
        'heading' => 'Zichtbaarheid',
        'public' => 'Openbaar',
        'private' => 'Privé',
        // The short version, next to a channel that does not exist yet.
        'public_hint' => 'Iedereen in de workspace kan meelezen en zich aansluiten.',
        'private_hint' => 'Alleen wie je toevoegt ziet dit kanaal bestaan.',
        // The long version, next to a channel that already has a history.
        'public_explained' => 'Iedereen in de workspace kan dit kanaal vinden, lezen en zich aansluiten. Gasten niet: die zien alleen wat voor hen is klaargezet.',
        'private_explained' => 'Alleen leden zien dit kanaal. Wie er nu in zit blijft erin; de rest raakt het kwijt.',
        'opening_up' => 'Let op: alles wat hier eerder is gezegd wordt hiermee leesbaar voor de hele workspace. Dit is niet terug te draaien door het kanaal weer privé te maken.',
    ],

    'layout' => [
        'heading' => 'Weergave',
        'chat' => 'Gesprek',
        'chat_hint' => 'Berichten onder elkaar, zoals een gewoon kanaal.',
        'feed' => 'Feed',
        'feed_hint' => 'Langere berichten met meer ruimte, zoals een nieuwsbrief of blog.',
    ],

    'create' => [
        'title' => 'Kanaal aanmaken',
        'description' => 'Kanalen gaan meestal over één onderwerp, project of team.',
        'name_placeholder' => 'bijv. marketing',
        'slug_hint' => 'Kleine letters en streepjes.',
        'slug_preview' => 'Wordt #:slug',
    ],

    'settings' => [
        'title' => 'Instellingen van #:channel',
        'tablist' => 'Kanaalinstellingen',

        'tabs' => [
            'general' => 'Algemeen',
            'general_description' => 'Hoe dit kanaal heet en waar het over gaat.',
            'messages' => 'Berichten',
            'messages_description' => 'Bepaal wie er berichten mag plaatsen in dit kanaal.',
            'tickets' => 'Tickets',
            'tickets_description' => 'Of dit kanaal tickets bijhoudt, wie ze mag aanmaken, en wat daarvan in het gesprek terechtkomt.',
            'links' => 'Knoppen',
            'links_description' => 'Snelkoppelingen naar plekken buiten de app, in een balk boven het gesprek.',
            'webhooks' => 'Webhooks',
            'webhooks_description' => 'Wat er van buitenaf in dit kanaal mag posten.',
        ],

        /*
         * Split around the code sample it wraps: the old name is styled in the
         * middle of the sentence, and the example itself is written differently
         * per language.
         */
        'name_hint_lead' => 'Spaties en hoofdletters worden omgezet naar streepjes en kleine letters. Links naar dit kanaal blijven werken, maar een',
        'name_hint_example' => '#oude-naam',
        'name_hint_tail' => 'in oudere berichten wordt gewone tekst.',

        'topic_hint' => 'Staat onder de naam bovenaan het gesprek.',
    ],

    'posting' => [
        'heading' => 'Wie mag berichten plaatsen',
        'everyone' => 'Iedereen in dit kanaal',
        'everyone_hint' => 'Een gewoon gesprek: elk lid kan berichten plaatsen.',
        'admins' => 'Alleen beheerders en de kanaalmaker',
        'admins_hint' => 'Een zendkanaal. Anderen kunnen nog wel reageren met een emoji en in threads antwoorden.',
        'replies_open' => 'Reageren in een thread toestaan',
        'replies_open_hint' => 'Uitzetten maakt dit een kanaal dat aankondigt en niet bespreekt. Bestaande threads blijven leesbaar.',
    ],

    'tickets' => [
        'heading' => 'Wie mag tickets aanmaken',
        'disabled' => 'Geen tickets',
        'disabled_hint' => 'Dit kanaal is alleen een gesprek.',
        'everyone' => 'Iedereen in dit kanaal',
        'everyone_hint' => 'Een klantkanaal: de klant kan zelf tickets aanmaken.',
        'members' => 'Alleen leden, geen gasten',
        'members_hint' => 'Gasten lezen de tickets wel, maar maken er geen aan.',
        'announce' => 'Meld tickets in het gesprek',
        'announce_hint' => 'Een kort bericht in het kanaal zodra een ticket wordt aangemaakt of gesloten, zodat wie alleen meeleest het ook ziet.',
        'announce_status' => 'Ook bij elke statuswijziging',
        'announce_status_hint' => 'Standaard uit: een kanaal dat elke stap meldt is een kanaal dat mensen dempen. Aanzetten als het werk in het gesprek gebeurt en niet op het bord.',
    ],

    'archive' => [
        'heading' => 'Kanaal archiveren',
        'explanation' => 'Alles blijft leesbaar, maar er kan niets meer geplaatst worden. Het kanaal verdwijnt uit de zijbalk en is terug te halen onder "Gearchiveerd".',
    ],

    'delete' => [
        'heading' => 'Kanaal verwijderen',
        'explanation' => 'Alle berichten, threads, tickets en webhooks van dit kanaal gaan mee. Dit is niet terug te draaien.',
        // Split around the channel name, which is shown in the typeface you
        // have to match while typing it.
        'confirm_lead' => 'Typ',
        'confirm_tail' => 'om te bevestigen',
        'confirm_button' => 'Definitief verwijderen',
    ],

    'members' => [
        'title' => 'Leden van :channel',
        'private_note' => 'Dit kanaal is privé — alleen wie hier staat ziet het bestaan.',
        'public_note' => 'Iedereen in de workspace kan dit kanaal lezen.',
        'in_channel' => 'In dit kanaal (:count)',
        'owner' => 'eigenaar',
        'add' => 'Toevoegen',
        'add_selected' => ':count toevoegen',
        'search_placeholder' => 'Zoek een teamgenoot…',
        'all_in' => 'Iedereen zit er al in.',
        'none_found' => 'Niemand gevonden.',
        'leave' => 'Kanaal verlaten',
        'cannot_leave' => 'Je hebt dit kanaal aangemaakt en kunt het daarom niet verlaten.',
        'remove' => ':name verwijderen',
        'remove_title' => 'Verwijderen uit kanaal',
        'remove_question' => ':name verwijderen?',
        'remove_private' => 'Hiermee verliest :name toegang tot #:channel en verdwijnt het kanaal uit hun zijbalk. Eerdere berichten blijven staan.',
        'remove_public' => ':name kan #:channel daarna nog wel lezen, maar niet meer meepraten tot ze zich opnieuw aansluiten.',
    ],
];
