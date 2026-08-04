<?php

/*
 * What the application says back when a request cannot be answered.
 *
 * Keyed by domain and then by what went wrong, in snake_case — chat.not_a_member
 * rather than chat.error_403. The status code is already on the response; a key
 * that repeats it says nothing the reader of this file does not know, and says
 * nothing about which of the several 403s this one is.
 */

return [
    'not_a_member' => 'Je bent geen lid van deze workspace.',
    'no_workspace_yet' => 'Je hoort nog bij geen enkele workspace.',
    'already_sent' => 'Dit bericht is al verstuurd.',
    'unknown_webhook' => 'Onbekende webhook.',

    /*
     * De weigeringen van de token-API. Eén zin voor drie gevallen — kanaal
     * bestaat niet, is niet van jou, of staat in een workspace die geen tokens
     * binnenlaat — want ze uit elkaar houden zou een beller laten aflopen welke
     * kanalen bestaan en waar deze persoon zit.
     */
    'api' => [
        'no_channel' => 'Kanaal niet gevonden.',
        'may_not_post' => 'Je mag niet posten in dit kanaal.',
    ],
    'channel_archived' => 'Dit kanaal is gearchiveerd.',
    'too_many_sections' => 'Je kunt maximaal :count groepen maken.',
    'too_many_status_rules' => 'Je hebt al :count regels.',
    'too_many_api_tokens' => 'Je hebt al :count actieve tokens.',
    'broadcast_no_author' => 'De afzender bestaat niet meer.',
    'broadcast_nowhere' => 'Er was geen kanaal meer over om dit in te plaatsen.',
    'broadcast_failed' => 'Er ging iets mis bij het versturen.',
    'broadcast_none_allowed' => 'In geen van die kanalen mag je posten.',
    'broadcast_posted' => '{1}Bericht geplaatst in 1 kanaal.|[2,*]Bericht geplaatst in :count kanalen.',
    'broadcast_scheduled' => '{1}Ingepland voor 1 kanaal.|[2,*]Ingepland voor :count kanalen.',
    'broadcast_withdrawn' => 'Rondzending ingetrokken.',
];
