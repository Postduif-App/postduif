<?php

/*
 * In- en uitklokken, en het scherm waarop je terugleest wat dat opleverde.
 *
 * Eigen bestand, net als bij de emoji en de rollen: het is een scherm met een
 * eigen onderwerp, en de zinnen eromheen gaan ergens anders over.
 */

return [
    'title' => 'Tijdregistratie',
    'description' => 'Je uren bij :workspace',

    'clock_in' => 'Inklokken',
    'clock_out' => 'Uitklokken',
    // Onder de knop in het gebruikersmenu, met de tijd die loopt erachter.
    'running_since' => 'Ingeklokt sinds :time',
    'not_running' => 'Niet ingeklokt',

    'clock_out_question' => 'Wil je uitklokken?',
    'clock_out_explanation' => 'Je staat :duration ingeklokt. Na het uitklokken telt deze periode mee in je week; kloppen de tijden niet, dan kun je ze op het klokscherm nog bijstellen.',
    'clock_out_confirm' => 'Uitklokken',
    'clock_out_cancel' => 'Nog niet',

    'calendar' => [
        'title' => 'Het afgelopen half jaar',
        'less' => 'Minder',
        'more' => 'Meer',
    ],

    'today' => 'Vandaag',
    'this_week' => 'Deze week',
    'week_of' => 'Week van :date',
    'previous_week' => 'Vorige week',
    'next_week' => 'Volgende week',
    'back_to_this_week' => 'Naar deze week',

    'day' => 'Dag',
    'from' => 'Van',
    'until' => 'Tot',
    'duration' => 'Duur',
    // In de bevestiging dat een dienst is afgesloten.
    'spoken_duration' => ':hours uur en :minutes minuten',
    'still_running' => 'loopt nog',
    'corrected' => 'bijgesteld',
    'over_limit' => 'Langer dan :hours uur — er telt :hours uur mee. Klopt dat niet, pas de tijden aan.',

    'empty' => 'Deze week staat er nog niets. Klokken doe je vanuit het menu onder je naam.',
    'no_hours_yet' => '—',

    'edit' => 'Aanpassen',
    'edit_title' => 'Periode aanpassen',
    'edit_explanation' => 'De tijden zoals ze op jouw klok stonden. Een dienst die na middernacht eindigde vul je in met de begindatum; dat de eindtijd eerder ligt is genoeg.',
    'date' => 'Datum',
    'started_at' => 'Begonnen om',
    'ended_at' => 'Gestopt om',
    'save' => 'Opslaan',
    'cancel' => 'Annuleren',

    'delete' => 'Verwijderen',
    'delete_question' => 'Deze periode weghalen?',
    'delete_explanation' => 'Hij telt daarna nergens meer mee. Was je wel aan het werk maar klopten de tijden niet, pas ze dan liever aan.',

    'preference' => [
        'title' => 'Status meebewegen',
        'explanation' => 'Zet inklokken je op Beschikbaar en uitklokken op Afwezig. Je statusregels blijven gewoon gelden: wat jij zelf zegt wint tot het venster van die regel voorbij is.',
        'label' => 'Laat de klok mijn status bijwerken',
    ],

    'colleagues' => [
        'title' => 'Collega\'s',
        'explanation' => 'Wat er deze week geklokt is, en wie er nu ingeklokt staat.',
        'clocked_in' => 'Nu ingeklokt',
        'since' => 'sinds :time',
        'empty' => 'Deze week heeft nog niemand geklokt.',
    ],

    'errors' => [
        'in_the_future' => 'Dat moment moet nog komen.',
        'ends_before_it_starts' => 'De eindtijd ligt voor de begintijd.',
        'end_required' => 'Deze periode is al afgesloten; die kan niet opnieuw gaan lopen. Begin liever een nieuwe.',
        'overlaps' => 'Je hebt al een periode die hier overheen valt. Twee keer dezelfde middag telt dubbel.',
    ],
];
