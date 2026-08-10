<?php

/*
 * Alles wat op het scherm staat rond een document: de lijst, het document zelf,
 * de editor en wat hij zegt terwijl hij bezig is.
 *
 * Een eigen bestand, zoals timeclock.php en workflows.php dat ook zijn. Een
 * document is niet één onderdeel maar een halve toepassing — een lijst, een
 * documentweergave, een editor met een menu en een balk — en die verspreiden
 * over vijf oppervlaktebestanden maakt ze alleen maar moeilijker te vinden.
 *
 * Wat elders al een woord had, staat hier niet: het tabblad is
 * conversation.view.documents, de kanaalinstelling staat in channels.php en de
 * keuzes van het beleid zijn een enum in enums.php.
 */

return [
    'conflict' => [
        'message' => ':name heeft dit document ondertussen opgeslagen. Herlaad de pagina om verder te werken met de nieuwste versie.',
        'somebody' => 'Iemand anders',
    ],
    'list' => [
        'title' => 'Documenten',
        'create' => 'Nieuw document',
        'updated' => 'Bijgewerkt :when door :who',
        'somebody' => 'iemand',
        'empty' => 'Nog geen documenten. Een document is de plek voor wat je anders elke paar weken opnieuw uitlegt: afspraken, een draaiboek, wie wat doet.',
    ],
    'slash' => [
        'heading' => 'Blokken',
        'empty' => 'Geen blok met die naam.',
        'blocks' => [
            'paragraph' => 'Tekst',
            'heading_one' => 'Kop 1',
            'heading_two' => 'Kop 2',
            'heading_three' => 'Kop 3',
            'bulleted_list' => 'Opsomming',
            'numbered_list' => 'Genummerde lijst',
            'todo_list' => 'Takenlijst',
            'blockquote' => 'Citaat',
            'callout' => 'Kader',
            'divider' => 'Scheidslijn',
        ],
    ],
    'toolbar' => [
        'label' => 'Opmaak',
        'bold' => 'Vet',
        'italic' => 'Cursief',
        'underline' => 'Onderstrepen',
        'strike' => 'Doorhalen',
        'code' => 'Code',
    ],
    'view' => [
        'back' => 'Terug naar de documenten',
        'untitled' => 'Naamloos',
        'title_label' => 'Titel van dit document',
        'moved' => 'Iemand anders heeft dit document bijgewerkt.',
        'dismiss' => 'Later',
        'delete' => 'Document verwijderen',
        'confirm' => 'Dit document verwijderen? Iedereen in het kanaal raakt het kwijt.',
        'reload' => 'Herladen',
        'saving' => 'Bezig met opslaan…',
        'saved' => 'Opgeslagen',
        'unsaved' => 'Niet opgeslagen',
    ],
    'create' => [
        'title' => 'Nieuw document',
        'description' => 'Geef het een naam. De inhoud schrijf je daarna in de editor.',
        'name' => 'Naam',
        'placeholder' => 'Afspraken met de klant',
        'cancel' => 'Annuleren',
        'submit' => 'Beginnen',
    ],
    'editor' => [
        'placeholder' => 'Typ iets, of druk op / voor blokken',
        'failed' => 'De editor kon niet geladen worden.',
        'reload' => 'Pagina herladen',
    ],
];
