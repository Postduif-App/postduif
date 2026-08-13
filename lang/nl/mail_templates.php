<?php

/*
 * The screen where a workspace rewrites its own contract mails, and the words
 * the placeholders go by here.
 *
 * The placeholder block below is not ordinary copy: these strings end up inside
 * somebody's saved text, and every one of them keeps working in every language
 * because RenderMailTemplate matches on the aliases of all supported languages
 * at once — see MailPlaceholder. Changing a word here does not break templates
 * that were written with the old one only as long as the old word is still
 * matched somewhere, so treat these as names rather than as sentences.
 */

return [
    'title' => 'Mailteksten',
    'description' => 'Wat er in de mails aan de ondertekenaars van :workspace staat',

    'intro' => 'Deze teksten gaan naar mensen buiten je workspace. Laat je een veld leeg, dan gebruiken we onze eigen tekst — die staat er alvast lichtgrijs in.',

    'kind' => 'Welke mail',
    'language' => 'Taal',
    'language_hint' => 'De mail gaat uit in de taal van degene die het contract verstuurt. Vul in wat je gebruikt; de rest blijft onze tekst.',

    'subject' => 'Onderwerp',
    'heading' => 'Kop',
    'body' => 'Tekst',
    'button_label' => 'Tekst op de knop',
    'button_label_hint' => 'De knop zelf blijft altijd staan.',

    'placeholders' => 'Wat je kunt invoegen',
    'placeholders_hint' => 'Klik om op de cursor in te voegen. Een regel met iets wat leeg blijft — geen vervaldatum bijvoorbeeld — valt in z\'n geheel weg.',

    'reset' => 'Terug naar onze tekst',
    'reset_confirm' => 'Je eigen tekst voor deze mail en taal wordt gewist.',

    'preview' => 'Voorbeeld',
    'preview_title' => 'Zo komt de mail eruit te zien',
    'preview_hint' => 'Met verzonnen gegevens, en met de tekst zoals hij nu in het formulier staat.',
    'preview_close' => 'Sluiten',

    'saved' => 'De mailteksten zijn opgeslagen.',

    'placeholder_not_here' => 'Deze mail weet niet wat {{:placeholder}} zou moeten zijn. Kies iets uit de lijst eronder.',

    'language_name' => [
        'nl' => 'Nederlands',
        'en' => 'Engels',
    ],

    /*
     * The word each placeholder goes by in Dutch. What somebody types between
     * double braces, and what the chips insert.
     */
    'placeholder' => [
        'button' => 'knop',
        'signer' => 'ondertekenaar',
        'sender' => 'afzender',
        'workspace' => 'workspace',
        'title' => 'titel',
        'message' => 'bericht',
        'expires' => 'vervaldatum',
        'signed_at' => 'ondertekend_op',
    ],

    'hint' => [
        'button' => 'Waar de knop komt te staan. Laat je hem weg, dan zetten we de knop onder je tekst.',
        'signer' => 'De naam van degene die deze mail krijgt.',
        'sender' => 'Wie het contract verstuurde, of je workspace als die persoon er niet meer is.',
        'workspace' => 'De naam van je workspace.',
        'title' => 'De titel van het contract.',
        'message' => 'Het bericht dat bij dit ene contract is meegestuurd, als er een is.',
        'expires' => 'De datum waarop de link verloopt, als er een deadline is.',
        'signed_at' => 'De datum waarop deze persoon tekende.',
    ],

    /*
     * The stand-ins the preview runs on. Made up on purpose and recognisably
     * so: a preview with real names in it invites somebody to check whether the
     * mail is right for that person instead of whether the text is right.
     */
    'sample' => [
        'workspace' => 'Bakker & Partners',
        'signer' => 'Anna de Vries',
        'sender' => 'Joris Bakker',
        'title' => 'Samenwerkingsovereenkomst 2027',
        'message' => 'Zoals besproken, met de wijziging in artikel 4 erin verwerkt.',
    ],
];
