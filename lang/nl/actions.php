<?php

/*
 * The dialogs that do something with a message or a workspace: filing a ticket,
 * sending one message to several channels, carrying a message elsewhere, and
 * asking somebody in.
 *
 * Grouped by the job the reader came to do rather than by which component draws
 * the dialog — the same wording turns up in a menu, a button and a heading, and
 * keying by component would make every refactor a rename.
 */

return [
    'cancel' => 'Annuleren',

    'ticket' => [
        'title' => 'Nieuw ticket',
        'title_from_message' => 'Ticket van dit bericht',
        'intro_from_message' => 'Het bericht blijft staan waar het staat; dit ticket verwijst ernaar terug.',
        'intro_picking' => 'Kies waar dit ticket bijgehouden wordt; alleen kanalen waar jij tickets mag aanmaken staan erbij.',
        'intro_channel' => 'Wordt bijgehouden in #:channel, zodat iedereen ziet wat er nog openstaat.',
        'channel_field' => 'Kanaal',
        'channel_placeholder' => 'Kies een kanaal',
        'title_field' => 'Titel',
        'title_placeholder' => 'Waar gaat het over?',
        'body_field' => 'Omschrijving',
        'body_placeholder' => 'Wat is er aan de hand, en wat heb je al geprobeerd?',
        'priority_field' => 'Prioriteit',
        'submit' => 'Ticket aanmaken',
    ],

    'broadcast' => [
        'title' => 'Bericht naar meerdere kanalen',
        'intro' => 'Elk kanaal krijgt een eigen bericht, zodat een reactie daar blijft waar hij hoort.',
        'body_field' => 'Bericht',
        'body_placeholder' => 'Wat wil je laten weten?',
        'tags_field' => 'Tags',
        'channels_field' => 'Kanalen',
        'via_tag' => 'via tag',
        'no_channels' => 'Je bent nog geen lid van een kanaal waar je in mag posten.',
        'reach' => '{0}Nog geen kanaal gekozen|{1}Gaat naar 1 kanaal|[2,*]Gaat naar :count kanalen',
        'submit' => 'Versturen',
        'schedule_later' => 'Later versturen',
        'schedule_at' => 'Versturen op',
        'schedule_now' => 'Toch nu versturen',
        'schedule_submit' => 'Inplannen',
        'pending_title' => 'Staat klaar',
        'pending_channels' => '{1}1 kanaal|[2,*]:count kanalen',
        'withdraw' => 'Intrekken',
    ],

    'forward' => [
        'title' => 'Bericht doorsturen',
        'intro' => 'De tekst gaat mee, met de naam van wie het oorspronkelijk zei. Bestanden blijven achter — die horen bij het oorspronkelijke bericht.',
        'attachments' => '{1}1 bestand gaat mee|[2,*]:count bestanden gaan mee',
        'target' => 'Naar welk kanaal?',
        'no_channels' => 'Je zit nog in geen enkel ander kanaal om iets naartoe te sturen.',
        'note_field' => 'Iets erbij zeggen?',
        'note_placeholder' => 'Optioneel',
        'submit' => 'Doorsturen',
    ],

    'invite' => [
        'title' => 'Uitnodigen voor :workspace',
        'intro' => 'De genodigde krijgt een mail met een link die twee weken geldig is.',
        'email_field' => 'E-mailadres',
        'email_placeholder' => 'naam@voorbeeld.nl',
        'role_question' => 'Wat wordt het?',
        'guest' => 'Gast',
        'guest_hint' => 'Iemand van buiten. Ziet alleen de kanalen die je hieronder aanvinkt.',
        'member' => 'Lid',
        'member_hint' => 'Hoort erbij. Vindt de openbare kanalen zelf en ziet wie er in de workspace zitten.',
        'guest_channels' => 'Kanalen voor deze gast',
        'no_channels' => 'Er zijn nog geen kanalen om iemand voor uit te nodigen.',
        'submit' => 'Uitnodiging sturen',
    ],
];
