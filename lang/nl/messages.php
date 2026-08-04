<?php

/*
 * The conversation itself: a message, what you can do to it, and what it says
 * about its own history.
 */

return [
    'deleted' => 'Dit bericht is verwijderd',
    'edited' => 'bewerkt',
    'bot' => 'Bot',
    'forwarded_from' => 'Doorgestuurd — oorspronkelijk van',
    'empty' => 'Nog geen berichten. Begin het gesprek.',

    'pinned' => 'Vastgepind',
    'pinned_by' => 'Vastgepind door :name',

    /*
     * "Jij en Anna reageerden met 👍". The verb agrees with the number of
     * reactors rather than with the number of names, because "3 anderen" is one
     * name and still takes the plural.
     */
    'reaction' => '{1}:names reageerde met :emoji|[2,*]:names reageerden met :emoji',
    'reaction_you' => 'Jij',
    'reaction_someone' => 'iemand anders',
    'reaction_others' => ':count anderen',

    'actions' => [
        'copy_text' => 'Tekst kopiëren',
        'copy_link' => 'Link naar dit bericht kopiëren',
        'quote' => 'Citeren',
        'quote_key' => 'Citeren (R)',
        'ticket' => 'Ticket van dit bericht',
        'forward' => 'Doorsturen naar een ander kanaal',
        'save' => 'Bewaren voor later',
        'unsave' => 'Niet meer bewaren',
        'pin' => 'Vastpinnen in dit kanaal',
        'unpin' => 'Losmaken',
        'edit' => 'Bericht bewerken',
        'edit_key' => 'Bericht bewerken (E)',
        'reply' => 'Antwoord in thread',
        'reply_key' => 'Antwoord in thread (T)',
        'delete' => 'Bericht verwijderen',
        'delete_key' => 'Bericht verwijderen (D)',
    ],

    'editor' => [
        'save' => 'Opslaan',
        'cancel' => 'Annuleren',
        'escape_hint' => 'annuleert',
    ],

    'delete' => [
        'question' => 'Dit bericht verwijderen?',
        'with_replies' => 'De antwoorden in de thread blijven staan; op deze plek komt "Dit bericht is verwijderd".',
        'for_everyone' => 'Het bericht verdwijnt voor iedereen in dit kanaal. Je kunt dit niet terugdraaien.',
        'cancel' => 'Annuleren',
        'confirm' => 'Verwijderen',
    ],
];
