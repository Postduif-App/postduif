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

    'field-types' => [
        'text' => 'Tekst',
        'multiline' => 'Tekst over meer regels',
        'date' => 'Datum',
        'checkbox' => 'Vinkje',
        'signature' => 'Handtekening',
        'initials' => 'Paraaf',
    ],

];
