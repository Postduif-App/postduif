<?php

/*
 * The two mails that go to people outside the workspace.
 *
 * These matter more for translation than anything on a screen inside the app:
 * the reader has no account, has set no preference, and this mail is the first
 * and sometimes only thing they see of Postduif. HandleLocale falls back to
 * their browser, but a mail has no browser — so these render in the sender's
 * workspace language, which is the best guess available.
 */

return [
    /*
     * Post die binnenkomt en een ticket wordt. Eén zin, en die is nodig: een
     * mail zonder onderwerp levert anders een ticket zonder naam op, en daar
     * kan niemand naar verwijzen.
     */
    'inbound' => [
        'no_subject' => 'Bericht zonder onderwerp',
    ],

    /*
     * En de andere kant op: wat een collega op het ticket schrijft, terug naar
     * degene die de mail stuurde. Geen sjabloon dat een workspace kan
     * herschrijven — de tekst is wat de collega typte, en dit zijn alleen de
     * regels eromheen.
     */
    'ticket_reply' => [
        'subject' => 'Re: [#:number] :title',
        'signed' => ':name — :workspace',
        'footer' => 'Antwoord gerust op deze mail; je reactie komt bij ticket #:number te staan.',
    ],

    'closing' => 'Groeten,',

    /*
     * How a date reads inside a mail. Here rather than in the call, because
     * "3 maart 2027 om 14:05" and "3 March 2027 at 14:05" differ by more than
     * the month name — the little word in the middle is part of the language,
     * and a format string hardcoded in PHP put a Dutch "om" in every English
     * mail for as long as it lived there.
     */
    'format' => [
        'date' => 'j F Y',
        'date_time' => 'j F Y \o\m H:i',
    ],

    'invitation' => [
        'heading' => 'Je bent uitgenodigd',
        'intro' => ':inviter nodigt je uit voor **:workspace**.',
        'guest' => 'Je doet mee als gast. Dat betekent dat je alleen de kanalen ziet waarvoor je bent uitgenodigd — de rest van de workspace blijft buiten beeld.',
        'channels' => 'Je krijgt toegang tot:',
        'button' => 'Uitnodiging accepteren',
        'expires' => 'Deze link verloopt op :date. Was deze uitnodiging niet voor jou bedoeld? Dan hoef je niets te doen.',
    ],

    /*
     * The two contract mails below are written as templates rather than as
     * loose sentences, because a workspace may replace them with a template of
     * its own — see WorkspaceMailTemplate. Everything in {{ }} is a placeholder
     * RenderMailTemplate knows how to fill in, and a line holding one that
     * comes up empty drops out whole: no deadline means no sentence about a
     * deadline, no note from the author means no quote.
     *
     * That is also why these are one blob per mail instead of a key per
     * paragraph. What somebody edits on the settings screen is "de tekst", and
     * an override that had to be given paragraph by paragraph would let a
     * workspace rewrite three of the four and leave ours sitting in between.
     */
    'contract' => [
        'subject' => '{{afzender}} vraagt je om {{titel}} te tekenen',
        'heading' => 'Er ligt een contract voor je klaar om te tekenen',
        'button' => 'Contract openen en tekenen',
        'body' => <<<'MARKDOWN'
            {{afzender}} vraagt je om "{{titel}}" te ondertekenen.

            > {{bericht}}

            {{knop}}

            Deze link verloopt op {{vervaldatum}}. Daarna kun je niet meer tekenen en moet er een nieuw verzoek gestuurd worden.

            Deze link is persoonlijk en staat op jouw naam. Stuur hem niet door — wie hem opent, tekent namens jou.

            Groeten,
            {{afzender}}
            MARKDOWN,
    ],

    'contract_signed' => [
        'subject' => 'Het ondertekende document: {{titel}}',
        'heading' => 'Hier is het ondertekende document',
        'button' => 'Ondertekende versie downloaden',
        'body' => <<<'MARKDOWN'
            Iedereen heeft gereageerd op "{{titel}}". Hierbij de ondertekende versie, met achterin een overzicht van wie wanneer heeft getekend.

            Jij hebt op {{ondertekend_op}} getekend.

            De PDF zit als bijlage bij deze mail. Bewaar hem goed: dit is jouw exemplaar.

            {{knop}}

            Werkt de bijlage niet, dan kun je het document via de knop hierboven ophalen. Die link is persoonlijk — stuur hem niet door.

            Groeten,
            {{afzender}}
            MARKDOWN,
    ],

    'transfer' => [
        'heading' => 'Er staan bestanden voor je klaar',
        'intro' => '{1}:sender heeft een bestand voor je klaargezet.|[2,*]:sender heeft :count bestanden voor je klaargezet.',
        'button' => 'Bestanden downloaden',
        'expires' => 'Deze link is voor jou gemaakt en verloopt op :date. Daarna zijn de bestanden weg. Kreeg je dit onverwacht? Dan hoef je niets te doen.',
    ],

    'test' => [
        'subject' => 'Testbericht van :workspace',
        'heading' => 'Dit werkt',
        'intro' => 'Dit bericht is verstuurd via de mailinstellingen van **:workspace**. Komt het aan, dan komt de rest ook aan.',
        'sender' => 'Kijk ook even naar de afzender hierboven: dat is het adres waarmee deze workspace voortaan mailt.',
    ],
];
