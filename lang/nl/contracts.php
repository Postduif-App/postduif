<?php

/*
 * Alles wat een mens leest rond een contract.
 *
 * De weigeringen bij het uploaden staan bovenaan en zijn met opzet uitgeschreven
 * in plaats van kort gehouden: elk van deze zinnen is het enige wat iemand te
 * horen krijgt op het moment dat zijn bestand niet doorkomt, en "er ging iets
 * mis" laat een mens met een dichte deur en geen sleutel achter.
 */

return [

    'upload' => [
        'empty' => 'Dit bestand is leeg of bevat geen pagina\'s.',
        'not-a-pdf' => 'Alleen PDF-bestanden kunnen ondertekend worden. Sla het document eerst op als PDF.',
        'unreadable' => 'Deze PDF kon niet verwerkt worden. Beveiligde of beschadigde bestanden komen er niet doorheen; sla het document opnieuw op zonder wachtwoord en probeer het dan nog eens.',
        'executable' => 'In deze PDF zit script of een ingesloten bestand. Dat kan niet ondertekend worden — sla het document opnieuw op als een gewone PDF, zonder formulierlogica of bijlagen.',
        'too-large' => 'Dit bestand is groter dan :max MB. Sla de PDF kleiner op — meestal scheelt "verkleind" of "standaard" in plaats van "drukwerk" al genoeg.',
        'too-many-pages' => 'Dit document heeft meer dan :max pagina\'s. Splits het op of stuur alleen het deel dat getekend moet worden.',
    ],

    'editor' => [
        'back' => 'Terug',
        'save' => 'Opslaan',
        'zoom_in' => 'Inzoomen',
        'zoom_out' => 'Uitzoomen',
        'tool' => 'Wat zet je neer',
        'tool_hint' => 'Kies een soort vak en klik op de pagina waar het moet komen. Slepen verplaatst, de hoekpunten maken groter of kleiner.',
        'selected' => 'Geselecteerd vak',
        'field_label' => 'Label',
        'required' => 'Verplicht in te vullen',
        'for_signer' => 'In te vullen door',
        'remove_field' => 'Vak verwijderen',
        'page_count' => '{1}1 pagina|[2,*]:count pagina\'s',
        'field_count' => '{0}nog geen vakken|{1}1 vak|[2,*]:count vakken',
        'frozen' => 'Dit contract is niet meer aan te passen. Er is al getekend, of het is ingetrokken — een vak verplaatsen zou veranderen waar iemand mee akkoord ging.',
        'failed' => 'Het document kon niet geladen worden.',
        'reload' => 'Pagina opnieuw laden',
    ],

    'field-types' => [
        'text' => 'Tekst',
        'multiline' => 'Tekst over meer regels',
        'date' => 'Datum',
        'checkbox' => 'Vinkje',
        'signature' => 'Handtekening',
        'initials' => 'Paraaf',
    ],

];
