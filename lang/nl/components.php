<?php

/*
 * Losse onderdelen die op meerdere schermen terugkomen.
 *
 * Wat hier staat hoort bij geen enkel scherm in het bijzonder: het
 * gebruikersmenu hangt onder elke pagina van de chat, het avatarveld staat op
 * zowel je profiel als dat van een workspace, en de passkey- en 2FA-blokken
 * worden vanuit de beveiligingspagina samengesteld.
 *
 * Gegroepeerd per onderdeel, want dat is wat verhuist. Een woord dat elders al
 * bestaat — "Opslaan", "Annuleren" — hoort daar te blijven en wordt hier
 * bewust niet nog een keer opgeschreven.
 */

return [
    /*
     * Het menu onder je eigen naam. Het zoekt zijn woorden zelf op: het hing
     * eerst aan acht chatpagina's die de tekst er stuk voor stuk in stopten,
     * en acht kopieën van dezelfde zin lopen vroeg of laat uiteen.
     */
    'user_menu' => [
        'set_status' => 'Status instellen',
        'appearance' => 'Weergave',
        'light' => 'Licht',
        'dark' => 'Donker',
        'system' => 'Systeem',
        'settings' => 'Instellingen',
        'log_out' => 'Uitloggen',
    ],

    'avatar' => [
        'hint' => 'png, jpg, gif of webp, tot 2 MB. Wordt bijgesneden tot een vierkant.',
        'choose' => 'Foto kiezen',
        'replace' => 'Andere foto',
        'remove' => 'Verwijderen',
    ],

    'locale' => [
        'label' => 'Taal',
        'follow_browser' => 'Volg mijn browser',
    ],

    'timezone' => [
        'label' => 'Tijdzone',
        'hint' => 'Waarin herhalende tijden gelezen worden, zoals een status die elke werkdag om negen uur ingaat.',
        'detected' => 'Je browser staat op :zone.',
        'adopt' => 'Overnemen',
    ],

    'guest_channels' => [
        'title' => 'Kanalen van :name',
        'description' => 'Een gast ziet alleen wat hier is aangevinkt — openbare kanalen vindt hij niet zelf.',
        'empty' => 'Er zijn nog geen kanalen om deze gast aan te koppelen.',
    ],

    'passkeys' => [
        'title' => 'Passkeys',
        'description' => 'Je passkeys, om zonder wachtwoord in te loggen',
        'empty' => 'Nog geen passkeys',
        'empty_hint' => 'Voeg een passkey toe en log in zonder wachtwoord',
        'unsupported' => 'Deze browser kent geen passkeys.',
        'add' => 'Passkey toevoegen',
        'name' => 'Naam van deze passkey',
        'name_placeholder' => 'Bijvoorbeeld: MacBook Pro, iPhone',
        'name_hint' => 'Aan de naam herken je deze passkey later terug.',
        'registering' => 'Bezig met toevoegen…',
        'register' => 'Passkey opslaan',
        'cancel' => 'Annuleren',
    ],

    'two_factor' => [
        'title' => 'Verificatie in twee stappen',
        'description' => 'Een tweede stap bij het inloggen',
        'enabled_explanation' => 'Bij het inloggen vragen we om een code die je authenticator-app laat zien.',
        'disabled_explanation' => 'Zet je dit aan, dan vragen we bij het inloggen om een code uit je authenticator-app.',
        'disable' => 'Uitzetten',
        'enable' => 'Aanzetten',
        'continue_setup' => 'Verder met instellen',

        // De dialoog waarin je de QR-code scant en de code terugtypt.
        'modal' => [
            'enabled_title' => 'Verificatie in twee stappen staat aan',
            'enabled_description' => 'Scan de QR-code of vul de sleutel in je authenticator-app in.',
            'enabled_button' => 'Sluiten',
            'verify_title' => 'Code controleren',
            'verify_description' => 'Vul de zescijferige code in die je authenticator-app laat zien',
            'setup_title' => 'Verificatie in twee stappen aanzetten',
            'setup_description' => 'Scan de QR-code of vul de sleutel in je authenticator-app in om dit af te maken',
            'continue' => 'Doorgaan',
            'manual' => 'of vul de sleutel zelf in',
            'copy_key' => 'Sleutel kopiëren',
            'back' => 'Terug',
            'confirm' => 'Bevestigen',
        ],
    ],

    'dev_login' => [
        'notice' => 'Alleen in development — direct inloggen',
    ],

    /*
     * De koptekst en de zijnavigatie van de schermen buiten de chat. Ze komen
     * uit de starterkit en staan er nog in de vorm waarin die ze meelevert.
     */
    'shell' => [
        'platform' => 'Platform',
        'navigation' => 'Navigatiemenu',
        'dashboard' => 'Overzicht',
        'repository' => 'Repository',
        'documentation' => 'Documentatie',
    ],
];
