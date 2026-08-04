<?php

/*
 * Je eigen account, en de schermen die daar los omheen hangen.
 *
 * Het verwijderen van je account, je herstelcodes en je passkeys horen bij de
 * persoon die is ingelogd. Daaronder staan de geheimenschermen: wat jij hebt
 * klaargezet, wat er op jouw verzoek is ingevuld, en de pagina waarop iemand
 * anders een geheim ophaalt. Die laatste wordt bezocht door mensen zonder
 * account hier — daar is de Engelse kant dus geen bijzaak.
 *
 * Gegroepeerd per scherm, niet per component: een dialoog die verhuist mag geen
 * hernoeming van alle sleutels betekenen.
 */

return [
    'delete' => [
        'title' => 'Account verwijderen',
        'description' => 'Je account en alles wat eraan hangt',
        'warning_title' => 'Let op',
        'warning' => 'Dit kun je niet terugdraaien.',
        'button' => 'Account verwijderen',
        'question' => 'Weet je zeker dat je je account wilt verwijderen?',
        'explanation' => 'Zodra je account weg is, verdwijnt alles wat eraan hangt voorgoed mee. Vul je wachtwoord in om te bevestigen dat je dit echt wilt.',
        'password' => 'Wachtwoord',
        'cancel' => 'Annuleren',
    ],

    'recovery_codes' => [
        'title' => 'Herstelcodes',
        'description' => 'Met een herstelcode kom je binnen als je je tweede stap kwijt bent. Bewaar ze in je wachtwoordmanager.',
        'show' => 'Herstelcodes tonen',
        'hide' => 'Herstelcodes verbergen',
        'regenerate' => 'Nieuwe codes maken',
        'list_label' => 'Herstelcodes',
        'loading' => 'Herstelcodes laden',
        /*
         * In tweeën, omdat de naam van de knop ertussen vet staat. Eén zin met
         * een placeholder leest prettiger, maar dan valt de nadruk weg — en die
         * knop is precies waar de zin je naartoe stuurt.
         */
        'explanation_intro' => 'Elke herstelcode werkt één keer en verdwijnt daarna. Heb je er meer nodig, klik dan op',
        'explanation_rest' => 'hierboven.',
    ],

    'passkeys' => [
        'added' => 'Toegevoegd :moment',
        'last_used' => 'Laatst gebruikt :moment',
        'remove' => 'Verwijderen',
        'remove_title' => 'Passkey verwijderen',
        'remove_question' => 'Weet je zeker dat je de passkey ":name" wilt verwijderen? Je kunt er daarna niet meer mee inloggen.',
        'removing' => 'Bezig met verwijderen…',
        'cancel' => 'Annuleren',
    ],

    'sent_secrets' => [
        'head' => 'Geheimen',
        'title' => 'Geheimen',
        'description' => 'Wat jij hebt klaargezet — versleuteld in je browser',
        'new' => 'Nieuwe link',
        'empty' => 'Je hebt nog niets klaargezet.',
        'empty_hint' => 'Een geheim wordt in je eigen browser versleuteld. Je krijgt één link, die je zelf doorgeeft — wij kunnen hem niet opnieuw maken.',
        'has_password' => 'Met wachtwoord',
        'for_nobody' => 'Voor niemand in het bijzonder',
        'for_person' => 'Voor :name',
        'revealed_at' => 'Opgehaald op :moment',
        'expired_at' => 'Verlopen op :moment',
        'expires_at' => 'Vervalt :moment',
        'revoke' => 'Intrekken',
        'revoke_question' => 'Dit geheim intrekken? De link werkt daarna niet meer.',
    ],

    'secret_answers' => [
        'title' => 'Gevraagde gegevens',
        'description' => 'Wat er is ingevuld',
        'asked_in' => 'Gevraagd in #:channel · :filled van :total ingevuld',
        'filled_by' => 'ingevuld door :name',
        'filled_by_at' => 'ingevuld door :name op :moment',
        'somebody' => 'iemand',
        'not_filled' => 'nog niet ingevuld',
        'show' => 'Tonen',
        'copy' => 'Kopiëren',
        'copied' => 'Gekopieerd',
        'burned' => 'Dit was de enige keer — de waarde is nu verwijderd. Kopieer hem voor je deze pagina sluit.',
        'failed' => 'Tonen is niet gelukt. Ververs de pagina en probeer opnieuw.',
        'seen_before' => 'Eerder bekeken op :moment.',
        'burn_note' => 'Dit verzoek verwijdert elke waarde zodra je hem hebt bekeken. Je krijgt hem één keer te zien, dus zorg dat je hem meteen kunt gebruiken.',
        'expiry_note' => 'Alleen jij kunt deze waarden zien — beheerders van de workspace niet. Na :date wordt het verzoek opgeruimd, waarden en al.',
    ],

    'secret_reveal' => [
        'head' => 'Geheim',
        'title' => 'Een geheim voor jou',
        'description' => ':sender heeft iets voor :recipient klaargezet.',
        'copy_now' => 'Kopieer het nu — het is hierna weg.',
        'expires_at' => 'Vervalt op :moment',
        'password' => 'Wachtwoord',
        'password_note' => 'De afzender heeft dit apart doorgegeven.',
        'submit' => 'Onthullen',
        'once' => 'Je kunt dit één keer bekijken. Daarna is het weg.',
        'copy' => 'Kopieer',
        'copied' => 'Gekopieerd',
        'gone_note' => 'Dit geheim is nu van de server verwijderd. Ververs je deze pagina, dan is het er niet meer.',
        'already_revealed' => 'Dit geheim is al opgehaald en bestaat niet meer. Vraag de afzender om een nieuwe als je het alsnog nodig hebt.',
        'expired' => 'Dit geheim is verlopen en bestaat niet meer. Vraag de afzender om een nieuwe.',
        'no_key' => 'Aan deze link ontbreekt het stuk achter het #, en dat is precies de sleutel. Vraag de afzender de link nog een keer te sturen — in zijn geheel.',
        'error_password' => 'Dat wachtwoord klopt niet.',
        'error_revealed' => 'Dit geheim is al opgehaald.',
        'error_gone' => 'Dit geheim is er niet meer.',
        'error_open' => 'Dit geheim kon niet worden geopend. Klopt de link helemaal, tot en met het deel achter het #?',
    ],

    'welcome' => [
        'head' => 'Welkom',
        'dashboard' => 'Naar de app',
        'log_in' => 'Inloggen',
        'register' => 'Registreren',
        'title' => 'Aan de slag',
        'intro' => 'Laravel heeft een enorm rijk ecosysteem.',
        'intro_second' => 'We raden je aan hiermee te beginnen.',
        'read_docs' => 'Lees de',
        'documentation' => 'documentatie',
        'watch_videos' => 'Bekijk videolessen op',
        'deploy' => 'Nu uitrollen',
    ],
];
