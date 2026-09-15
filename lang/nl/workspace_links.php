<?php

/*
 * Het scherm waarop een workspace zijn eigen knoppen beheert.
 *
 * Apart bestand, net als bij de emoji en de rollen: een scherm met een eigen
 * onderwerp, en de teksten eromheen gaan over iets anders.
 */

return [
    'title' => 'Snelkoppelingen',
    'description' => 'Eigen knoppen in het menu van :workspace',

    'explanation' => 'Zet een adres achter een knop en kies wie hem ziet. De knoppen staan in het menu onder de naam van de workspace, en zijn ook te vinden via zoeken.',

    'label' => 'Naam',
    'label_placeholder' => 'bijvoorbeeld: Urenportaal',
    'url' => 'Adres',
    'url_placeholder' => 'https://',
    'url_hint' => 'Moet met http:// of https:// beginnen. De knop opent in een nieuw tabblad.',
    'emoji' => 'Icoon',
    'emoji_hint' => 'Optioneel. Zonder icoon krijgt de knop een pijltje.',

    'roles' => 'Zichtbaar voor',
    // Waarom dit er zo staat: een knop zonder rollen is een knop die niemand
    // ziet, en dat is makkelijker per ongeluk te maken dan terug te vinden.
    'roles_hint' => 'Vink aan wie de knop in zijn menu krijgt. Zonder vinkjes ziet niemand hem.',
    'roles_none' => 'Niemand — deze knop staat in niemands menu.',
    'roles_all' => 'Iedereen',
    'roles_external' => 'van buiten',

    'add' => 'Knop toevoegen',
    'adding' => 'Bezig…',
    'save' => 'Opslaan',
    'edit' => 'Wijzigen',
    'cancel' => 'Annuleren',
    'delete' => 'Verwijderen',
    'delete_question' => 'Weet je zeker dat je :label weghaalt?',
    'delete_explanation' => 'De knop verdwijnt uit ieders menu. Het adres erachter blijft gewoon werken.',

    'move_up' => 'Naar boven',
    'move_down' => 'Naar beneden',

    'empty' => 'Nog geen knoppen. De eerste is meestal het systeem waar iedereen de hele dag in zit.',
    'too_many' => 'Meer dan :count knoppen is meer dan een menu leesbaar houdt.',
];
