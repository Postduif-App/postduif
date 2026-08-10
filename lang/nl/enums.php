<?php

/*
 * Every enum case, as it is read on screen.
 *
 * Keyed enums.<enum>.<method>.<Case>, with the case in PascalCase exactly as
 * PHP spells it. Matching the code rather than a slug means a renamed case
 * breaks the key visibly instead of quietly falling back to the key string.
 */

return [
    'attachment-type' => [
        'hint' => [
            'Images' => 'png, jpg, gif, webp — worden in het gesprek getoond',
            'Video' => 'mp4, webm, mov — spelen af in het gesprek',
            'Audio' => 'mp3, m4a, ogg, wav',
            'Documents' => 'pdf, Word, Excel, PowerPoint, txt, csv',
            'Archives' => 'zip, 7z, tar, gz',
        ],
        'label' => [
            'Images' => 'Afbeeldingen',
            'Video' => 'Video\'s',
            'Audio' => 'Audio',
            'Documents' => 'Documenten',
            'Archives' => 'Archieven',
        ],
    ],
    'availability' => [
        'description' => [
            'Available' => 'Gewoon bereikbaar.',
            'Away' => 'Je bent er even niet; meldingen komen wel gewoon binnen.',
            'DoNotDisturb' => 'Er gaan geen meldingen uit. Vermeldingen blijven staan tot je terug bent.',
        ],
        'label' => [
            'Available' => 'Beschikbaar',
            'Away' => 'Afwezig',
            'DoNotDisturb' => 'Niet storen',
        ],
    ],
    'channel-document-policy' => [
        'description' => [
            'Disabled' => 'Dit kanaal houdt geen documenten bij.',
            'Everyone' => 'Iedereen in dit kanaal schrijft mee, gasten inbegrepen.',
            'Members' => 'Gasten lezen de documenten wel, maar schrijven er niet in.',
        ],
        'label' => [
            'Disabled' => 'Geen documenten',
            'Everyone' => 'Iedereen in dit kanaal',
            'Members' => 'Alleen leden, geen gasten',
        ],
    ],
    'channel-layout' => [
        'description' => [
            'Chat' => 'Berichten onder elkaar, zoals een gewoon gesprek.',
            'Feed' => 'Langere berichten met meer ruimte, zoals een nieuwsbrief of blog.',
        ],
        'getLabel' => [
            'Chat' => 'Gesprek',
            'Feed' => 'Feed',
        ],
    ],
    'channel-posting-policy' => [
        'description' => [
            'Everyone' => 'Een gewoon gesprek: elk lid kan berichten plaatsen.',
            'Admins' => 'Een zendkanaal: anderen kunnen wel reageren en in threads antwoorden.',
        ],
        'label' => [
            'Everyone' => 'Iedereen in dit kanaal',
            'Admins' => 'Alleen beheerders en de kanaalmaker',
        ],
    ],
    'channel-ticket-policy' => [
        'description' => [
            'Disabled' => 'Dit kanaal is alleen een gesprek.',
            'Everyone' => 'Een klantkanaal: de klant kan zelf tickets aanmaken.',
            'Members' => 'Gasten lezen de tickets wel, maar maken er geen aan.',
        ],
        'label' => [
            'Disabled' => 'Geen tickets',
            'Everyone' => 'Iedereen in dit kanaal',
            'Members' => 'Alleen leden, geen gasten',
        ],
    ],
    'channel-type' => [
        'getLabel' => [
            'Public' => 'Openbaar',
            'Private' => 'Privé',
            'Direct' => 'DM',
        ],
    ],
    'inbox-item-type' => [
        'label' => [
            'Mention' => 'Genoemd',
            'Reply' => 'Antwoord',
            'ThreadReply' => 'Thread',
            'PollVote' => 'Poll',
        ],
    ],
    'member-panel-visibility' => [
        'label' => [
            'Off' => 'Uit',
            'Everyone' => 'Iedereen in de workspace',
            'Admins' => 'Alleen beheerders en de eigenaar',
        ],
    ],
    'ticket-priority' => [
        'label' => [
            'Low' => 'Laag',
            'Normal' => 'Normaal',
            'High' => 'Hoog',
            'Urgent' => 'Urgent',
        ],
    ],
    'ticket-status' => [
        'description' => [
            'Open' => 'Binnengekomen, nog niemand opgepakt.',
            'InProgress' => 'Iemand is hiermee bezig.',
            'Waiting' => 'De bal ligt bij de klant.',
            'Resolved' => 'Afgehandeld, wacht op bevestiging.',
            'Closed' => 'Definitief afgerond.',
        ],
        'label' => [
            'Open' => 'Open',
            'InProgress' => 'In behandeling',
            'Waiting' => 'Wacht op klant',
            'Resolved' => 'Opgelost',
            'Closed' => 'Gesloten',
        ],
    ],
    'transfer-audience' => [
        'description' => [
            'Everyone' => 'Wie de link heeft, kan downloaden. Doorsturen werkt dus ook.',
            'WorkspaceMembers' => 'De ontvanger moet inloggen en lid zijn. Doorgestuurd naar buiten levert niets op.',
            'NamedRecipients' => 'Iedereen krijgt een eigen link gemaild. Doorsturen kan nog steeds, maar je ziet het aan de tellers en je trekt één adres in zonder de rest te raken.',
        ],
        'label' => [
            'Everyone' => 'Iedereen met de link',
            'WorkspaceMembers' => 'Alleen leden van deze workspace',
            'NamedRecipients' => 'Alleen deze e-mailadressen',
        ],
    ],
    'workflow-branch' => [
        'label' => [
            'Then' => 'Als het klopt',
            'Else' => 'Zo niet',
        ],
    ],
    'workflow-condition-match' => [
        'label' => [
            'All' => 'aan alle regels wordt voldaan',
            'Any' => 'aan één van de regels wordt voldaan',
        ],
    ],
    'workflow-condition-operator' => [
        'label' => [
            'Equals' => 'Is gelijk aan',
            'NotEquals' => 'Is niet gelijk aan',
            'Contains' => 'Bevat',
            'NotContains' => 'Bevat niet',
            'IsEmpty' => 'Is leeg',
            'IsNotEmpty' => 'Is niet leeg',
        ],
    ],
    'workflow-condition-outcome' => [
        'label' => [
            'Skip' => 'sla alleen deze stap over',
            'Stop' => 'stop de hele workflow',
        ],
    ],
    'workflow-run-status' => [
        'label' => [
            'Running' => 'Bezig',
            'Waiting' => 'Wacht',
            'Succeeded' => 'Klaar',
            'Stopped' => 'Gestopt',
            'Failed' => 'Mislukt',
        ],
    ],
    'workflow-step-kind' => [
        'label' => [
            'Action' => 'Stap',
            'Branch' => 'Splitsing',
        ],
    ],
    'workflow-step-status' => [
        'label' => [
            'Succeeded' => 'Uitgevoerd',
            'Skipped' => 'Overgeslagen',
            'Failed' => 'Mislukt',
        ],
    ],
    'workspace-ability' => [
        'label' => [
            'ManageWorkspace' => 'De workspace beheren',
            'InviteMembers' => 'Mensen uitnodigen',
            'SeeMembers' => 'Zien wie er meedoet',
            'CreateChannels' => 'Kanalen maken',
            'SendTransfers' => 'Bestanden versturen',
            'BroadcastMention' => '@here en @everyone gebruiken',
            'ManageWorkflows' => 'Workflows schrijven',
            'CreateForms' => 'Formulieren maken',
            'ShareFormsPublicly' => 'Formulieren buiten de workspace delen',
            'SeeHours' => 'Uren van collega\'s inzien',
            'DeleteBotMessages' => 'Berichten van bots verwijderen',
        ],
        'description' => [
            'ManageWorkspace' => 'De naam, de rollen, de rechten en het uiterlijk. Wie dit heeft, kan ook zichzelf en anderen meer geven — het is het enige recht dat bij alle andere kan.',
            'InviteMembers' => 'Iemand van binnen of buiten binnenhalen, en uitnodigingen weer intrekken.',
            'SeeMembers' => 'De ledenlijst van de workspace en van een kanaal. Zonder dit zie je alleen wie er in het gesprek langskomt.',
            'CreateChannels' => 'Een nieuw kanaal openen. Meedoen in bestaande kanalen staat hier los van.',
            'SendTransfers' => 'Bestanden klaarzetten achter een link die ook buiten de workspace werkt.',
            'BroadcastMention' => 'Een heel kanaal in één keer een melding geven. Zonder dit zijn die vermeldingen niet te kiezen en bereiken ze niemand.',
            'ManageWorkflows' => 'Dingen schrijven die de workspace zelf doet. Ze lopen met de rechten van wie ze schreef.',
            'CreateForms' => 'Een vragenlijst opstellen en in een kanaal zetten. De antwoorden komen bij de maker terecht.',
            'ShareFormsPublicly' => 'Een formulier achter een link zetten die ook zonder account werkt. Wie dit heeft, laat mensen van buiten in deze workspace schrijven.',
            'DeleteBotMessages' => 'Berichten weghalen die door een webhook of een workflow geplaatst zijn. Gaat niet over wat mensen zelf schrijven — dat blijft van henzelf. Wie een kanaal beheert kon dit al voor dat kanaal.',
            'SeeHours' => 'Zien hoeveel uur collega\'s deze week geklokt hebben en wie er nu ingeklokt staat. Iedereen ziet sowieso zijn eigen uren; dit gaat over die van een ander.',
        ],
    ],
    'workspace-accent' => [
        'label' => [
            'Neutral' => 'Neutraal',
            'Indigo' => 'Indigo',
            'Blue' => 'Blauw',
            'Emerald' => 'Groen',
            'Amber' => 'Amber',
            'Rose' => 'Roze',
        ],
    ],
    'workspace-font' => [
        'label' => [
            'InstrumentSans' => 'Instrument Sans',
            'Inter' => 'Inter',
            'Figtree' => 'Figtree',
            'Ubuntu' => 'Ubuntu',
            'JetBrainsMono' => 'JetBrains Mono',
            'System' => 'Systeemlettertype',
        ],
    ],
    'system-role' => [
        'getLabel' => [
            'Owner' => 'Eigenaar',
            'Admin' => 'Beheerder',
            'Member' => 'Lid',
            'Guest' => 'Gast',
        ],
    ],
];
