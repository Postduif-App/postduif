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
        'open_in_app' => 'Openen in Pcom',
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

    'invitation' => [
        'subject' => ':inviter nodigt je uit voor :workspace',
    ],
];
