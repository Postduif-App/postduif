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
    'closing' => 'Groeten,',

    'invitation' => [
        'heading' => 'Je bent uitgenodigd',
        'intro' => ':inviter nodigt je uit voor **:workspace**.',
        'guest' => 'Je doet mee als gast. Dat betekent dat je alleen de kanalen ziet waarvoor je bent uitgenodigd — de rest van de workspace blijft buiten beeld.',
        'channels' => 'Je krijgt toegang tot:',
        'button' => 'Uitnodiging accepteren',
        'expires' => 'Deze link verloopt op :date. Was deze uitnodiging niet voor jou bedoeld? Dan hoef je niets te doen.',
    ],

    'contract' => [
        'heading' => 'Er ligt een contract voor je klaar om te tekenen',
        'intro' => ':sender vraagt je om ":title" te ondertekenen.',
        'button' => 'Contract openen en tekenen',
        'expires' => 'Deze link verloopt op :date. Daarna kun je niet meer tekenen en moet er een nieuw verzoek gestuurd worden.',
        'personal' => 'Deze link is persoonlijk en staat op jouw naam. Stuur hem niet door — wie hem opent, tekent namens jou.',
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
