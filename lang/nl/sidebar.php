<?php

/*
 * The navigation column: the rail down the left and the channel list beside it.
 *
 * Grouped by where a reader finds it rather than by which component draws it —
 * a heading is a heading whether the sidebar or a dialog puts it there, and
 * keying by component would make every refactor a rename.
 */

return [
    'toolbar' => 'Werkbalk',

    'rail' => [
        'inbox' => 'Inbox',
        'board' => 'Prikbord',
        'secrets' => 'Geheimen',
        'saved' => 'Bewaard',
        'tickets' => 'Tickets',
        'transfers' => 'Versturen',
        'forms' => 'Formulieren',
        'timeclock' => 'Tijdregistratie',
        'broadcast' => 'Rondsturen',
    ],

    'headings' => [
        'favorites' => 'Favorieten',
        'channels' => 'Kanalen',
        'directs' => 'Directe berichten',
        'archived' => 'Gearchiveerd',
    ],

    'channel' => [
        'muted' => 'Meldingen staan uit',
        'open_tickets' => '{1}1 openstaand ticket|[2,*]:count openstaande tickets',
        'threads_show' => 'Threads in :channel tonen',
        'threads_hide' => 'Threads in :channel verbergen',
        'hide_direct' => 'Gesprek met :channel uit je zijbalk halen',
        'hide_direct_hint' => 'Uit je zijbalk halen. De ander merkt er niets van, en een nieuw bericht brengt het gesprek terug.',
        'restore' => ':channel heropenen',
        'none' => 'Geen kanalen',
        'no_directs' => 'Nog geen gesprekken',
        'first_channel' => 'Eerste kanaal maken',
        'add_channel' => 'Kanaal toevoegen',
        'start_conversation' => 'Start een gesprek',
        'new_conversation' => 'Nieuw gesprek',
    ],

    'section' => [
        'rename' => 'Groep hernoemen',
        'rename_named' => 'Groep ":name" hernoemen',
        'name_field' => 'Groepsnaam',
        'empty' => 'Nog geen kanalen in deze groep.',
    ],

    'thread' => [
        'menu' => 'Thread sluiten of dempen',
        'question' => 'Wat wil je met deze thread?',
        'close' => 'Sluiten',
        'mute' => 'Dempen',
        'unmute' => 'Dempen opheffen',
        'cancel' => 'Annuleren',
        'explain_close' => 'haalt hem uit jouw zijbalk; zodra er weer iets gezegd wordt, komt hij terug.',
        'explain_mute' => 'houdt hem uit je inbox, ook bij nieuwe antwoorden. Voor de anderen verandert er niets.',
        'explain_unmute' => 'laat nieuwe antwoorden weer in je inbox landen.',
        'replies' => '{1}1 antwoord|[2,*]:count antwoorden',
        'deleted' => 'Bericht verwijderd',
    ],

    'workspace' => [
        'invite' => 'Mensen uitnodigen',
        'members' => 'Leden',
        'settings' => 'Workspace-instellingen',
        'switch' => 'Naar een andere workspace',
    ],
];
