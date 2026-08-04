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

    'transfer' => [
        'heading' => 'Er staan bestanden voor je klaar',
        'intro' => '{1}:sender heeft een bestand voor je klaargezet.|[2,*]:sender heeft :count bestanden voor je klaargezet.',
        'button' => 'Bestanden downloaden',
        'expires' => 'Deze link is voor jou gemaakt en verloopt op :date. Daarna zijn de bestanden weg. Kreeg je dit onverwacht? Dan hoef je niets te doen.',
    ],
];
