<?php

/*
 * Het scherm waarop een workspace zijn eigen emoji uploadt.
 *
 * Apart bestand, net als bij de rollen: het is een scherm met een eigen
 * onderwerp, en de teksten eromheen gaan over iets anders.
 */

return [
    'title' => 'Emoji',
    'description' => 'De eigen plaatjes van :workspace',

    // Geen dubbele punt met een woord erachter in deze zin: Laravel leest :naam
    // als een placeholder, en een uitleg over shortcodes die zichzelf laat
    // vervangen is precies de grap die niemand ziet aankomen.
    'explanation' => 'Upload een plaatje, geef het een naam, en iedereen hier kan het tussen dubbele punten typen — in een bericht en als reactie.',

    'name' => 'Naam',
    'name_placeholder' => 'bijvoorbeeld: shipit',
    'name_hint' => 'Kleine letters, cijfers, - en _.',
    'image' => 'Plaatje',
    'image_hint' => 'png, jpg, gif of webp, maximaal 512 kB. Een gif blijft bewegen.',
    'upload' => 'Toevoegen',
    'uploading' => 'Bezig…',

    'preview' => 'Zo ziet het eruit',
    'added_by' => 'Toegevoegd door :name',
    'added_by_unknown' => 'Toegevoegd',
    'delete' => 'Verwijderen',
    'delete_question' => 'Weet je zeker dat je :name weghaalt?',
    'delete_explanation' => 'Berichten waarin hij staat blijven zoals ze zijn; daar staat dan weer gewoon de naam die je typte.',
    'cancel' => 'Annuleren',

    'empty' => 'Nog geen eigen emoji. De eerste is meestal het logo van iets.',
    'count' => '{1}1 emoji|[2,*]:count emoji',
    'too_many' => 'Meer dan :count emoji in één workspace is meer dan een picker leesbaar houdt.',
];
