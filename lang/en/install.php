<?php

/*
 * The first screen of an installation that consists of nothing yet: one
 * account, one workspace, and after that this screen no longer exists.
 */

return [
    'installed' => 'Welcome, :name. Your platform is ready.',

    'head' => 'Installation',
    'eyebrow' => 'New installation',
    'headline' => 'Set up your Postduif.',
    'intro' => 'This installation is still empty. Create the administrator account and name your first workspace — after that this screen is gone.',

    'steps' => [
        'account' => [
            'title' => 'Administrator',
            'body' => 'This account gets the admin panel and every workspace on this platform. You can appoint more of them later.',
        ],
        'workspace' => [
            'title' => 'Workspace',
            'body' => 'Where the work happens. You own it straight away, with a first channel to start in.',
        ],
        'rest' => [
            'title' => 'The rest',
            'body' => 'Inviting colleagues, switching features on or off and picking the house style all happen from inside the workspace.',
        ],
    ],

    'form' => [
        'title' => 'Administrator account',
        'name' => 'Your name',
        'name_placeholder' => 'First and last name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Repeat password',
        'workspace_title' => 'First workspace',
        'workspace' => 'Name',
        'workspace_placeholder' => 'The name of your company or team',
        'workspace_hint' => 'We derive the address from this. Both can be changed later.',
        'submit' => 'Finish installation',
        'submitting' => 'Setting things up…',
    ],

    /*
     * The screen hands out platform-wide rights to whoever finds it first. That
     * has to be said out loud: somebody who has just stood up a server and not
     * got round to this yet should know the address is open until they do.
     */
    'warning' => 'For as long as this screen exists, anybody who knows the address can become the administrator here. Finish the installation before you share the server with anyone.',
];
