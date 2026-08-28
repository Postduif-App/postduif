<?php

/*
 * What arrives by mail and by push.
 *
 * trans_choice is used where a count decides the wording — Laravel's own
 * pluralisation, so no library had to be added for it. The {1}/[2,*] form is
 * deliberate over the shorter one|many: "Eén nieuw bericht" is not "1 nieuw
 * bericht", and only the explicit form lets one be written out.
 */

return [
    'greeting' => 'Hoi :name,',

    'activity' => [
        'subject_mentions' => '{1}Iemand noemde je in :workspace|[2,*]:count keer genoemd in :workspace',
        'subject_unread' => '{1}Eén nieuw bericht in :workspace|[2,*]:count nieuwe berichten in :workspace',
        'intro' => 'Er is gepraat in :workspace terwijl je er niet was.',
        'messages' => '{1}:count bericht|[2,*]:count berichten',
        'mentions' => ':countx genoemd',
        'open' => 'Openen',
        'open_in_app' => 'Openen in Postduif',
        'preferences' => 'Instellen hoe vaak je dit krijgt kan bij [Notificaties](:url).',
    ],

    'tickets' => [
        'subject' => '{1}Een ticket blijft liggen|[2,*]:count tickets blijven liggen',
        'intro' => 'Deze tickets blijven liggen:',
        'overdue' => 'over de streefdatum',
        'unanswered' => 'nog geen antwoord',
        'open' => 'Openen',
    ],

    /*
     * De onderwerpregels van de twee mails naar buiten. De inhoud staat in
     * mail.php; het onderwerp hoort in dezelfde taal als de brief die eronder
     * hangt, en stond hier als enige nog uitgetypt in het Nederlands.
     */
    'transfer' => [
        'subject' => ':sender stuurt je :what',
        'files' => 'bestanden',
    ],

    'contract' => [
        'subject_signed' => ':name heeft :title getekend',
        'subject_declined' => ':name tekent :title niet',
        'subject_completed' => ':title is rond',
        'body_signed' => '{0}:name heeft ":title" getekend.|{1}:name heeft ":title" getekend. Er is nog één iemand die moet tekenen.|[2,*]:name heeft ":title" getekend. Er zijn nog :count mensen die moeten tekenen.',
        'body_declined' => ':name heeft laten weten ":title" niet te tekenen.',
        'body_completed' => 'Iedereen die gevraagd was heeft gereageerd op ":title".',
        'tally' => ':signed van de :total gevraagde mensen hebben getekend.',
        'download' => 'Ondertekende versie downloaden',
        'no_copy_yet' => 'De ondertekende versie kon nog niet samengesteld worden. De handtekeningen staan vast; je kunt het downloaden later opnieuw proberen vanuit het overzicht.',
    ],

    'invitation' => [
        'subject' => ':inviter nodigt je uit voor :workspace',
    ],

    'instant' => [
        'subject_mention' => 'Genoemd in :channel',
        'subject_message' => 'Nieuw bericht in :channel',
        'body' => ':author stuurde een bericht',
    ],

    'test_push' => [
        'title' => 'Postduif',
        'body' => 'Als je dit ziet, werken browsermeldingen op dit apparaat.',
    ],
];
