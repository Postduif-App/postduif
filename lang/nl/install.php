<?php

/*
 * Het eerste scherm van een installatie die nog nergens uit bestaat: één
 * account, één workspace, en daarna bestaat dit scherm niet meer.
 */

return [
    'installed' => 'Welkom, :name. Je platform staat klaar.',

    'head' => 'Installatie',
    'eyebrow' => 'Nieuwe installatie',
    'headline' => 'Zet je Postduif op.',
    'intro' => 'Deze installatie is nog leeg. Maak het beheerdersaccount aan en geef je eerste workspace een naam — daarna is dit scherm weg.',

    'steps' => [
        'account' => [
            'title' => 'Beheerder',
            'body' => 'Dit account krijgt toegang tot het adminpanel en tot elke workspace op dit platform. Je kunt er later meer aanwijzen.',
        ],
        'workspace' => [
            'title' => 'Workspace',
            'body' => 'De plek waar het werk gebeurt. Je bent er meteen eigenaar van, met een eerste kanaal om in te beginnen.',
        ],
        'rest' => [
            'title' => 'De rest',
            'body' => 'Collega\'s uitnodigen, functies aan- of uitzetten en de huisstijl kiezen doe je straks vanuit de workspace zelf.',
        ],
    ],

    'form' => [
        'title' => 'Beheerdersaccount',
        'name' => 'Je naam',
        'name_placeholder' => 'Voor- en achternaam',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'password_confirmation' => 'Wachtwoord herhalen',
        'workspace_title' => 'Eerste workspace',
        'workspace' => 'Naam',
        'workspace_placeholder' => 'De naam van je bedrijf of team',
        'workspace_hint' => 'Het adres leiden we hiervan af. Je kunt beide later aanpassen.',
        'submit' => 'Installatie afronden',
        'submitting' => 'Bezig met opzetten…',
    ],

    /*
     * Het scherm deelt platformrechten uit aan wie hem als eerste vindt. Dat
     * moet er staan: wie een server net heeft opgezet en er nog even niet aan
     * toe komt, moet weten dat dit adres tot die tijd open ligt.
     */
    'warning' => 'Zolang dit scherm bestaat, kan iedereen die het adres kent hier de beheerder worden. Rond de installatie af voordat je de server met anderen deelt.',
];
