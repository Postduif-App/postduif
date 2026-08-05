<?php

/*
 * Clocking in and out, and the screen where you read back what that produced.
 *
 * Its own file, like the emoji and the roles: a screen with a subject of its
 * own, and the sentences around it are about something else.
 */

return [
    'title' => 'Time tracking',
    'description' => 'Your hours at :workspace',

    'clock_in' => 'Clock in',
    'clock_out' => 'Clock out',
    // Under the button in the user menu, with the running time behind it.
    'running_since' => 'Clocked in since :time',
    'not_running' => 'Not clocked in',

    'clock_out_question' => 'Clock out?',
    'clock_out_explanation' => 'You have been clocked in for :duration. Once you clock out this stretch counts towards your week; if the times are wrong you can still adjust them on the clock screen.',
    'clock_out_confirm' => 'Clock out',
    'clock_out_cancel' => 'Not yet',

    'calendar' => [
        'title' => 'The past half year',
        'less' => 'Less',
        'more' => 'More',
    ],

    'today' => 'Today',
    'this_week' => 'This week',
    'week_of' => 'Week of :date',
    'previous_week' => 'Previous week',
    'next_week' => 'Next week',
    'back_to_this_week' => 'Back to this week',

    'day' => 'Day',
    'from' => 'From',
    'until' => 'Until',
    'duration' => 'Length',
    // In the sentence confirming a shift is over.
    'spoken_duration' => ':hours hours and :minutes minutes',
    'still_running' => 'still running',
    'corrected' => 'adjusted',
    'over_limit' => 'Longer than :hours hours — :hours hours are counted. If that is wrong, adjust the times.',

    'empty' => 'Nothing here this week yet. You clock in from the menu under your name.',
    'no_hours_yet' => '—',

    'edit' => 'Adjust',
    'edit_title' => 'Adjust this stretch',
    'edit_explanation' => 'The times as they read on your own clock. For a shift that ended after midnight, use the day it began; an end time that is earlier than the start is enough to say so.',
    'date' => 'Date',
    'started_at' => 'Started at',
    'ended_at' => 'Stopped at',
    'save' => 'Save',
    'cancel' => 'Cancel',

    'delete' => 'Remove',
    'delete_question' => 'Remove this stretch?',
    'delete_explanation' => 'It stops counting anywhere after that. If you did work but the times were wrong, adjust them instead.',

    'preference' => [
        'title' => 'Status follows the clock',
        'explanation' => 'Clocking in sets you to Available and clocking out to Away. Your status rules still apply: what you say yourself wins until that rule\'s window is over.',
        'label' => 'Let the clock update my status',
    ],

    'colleagues' => [
        'title' => 'Colleagues',
        'explanation' => 'What was clocked this week, and who is clocked in right now.',
        'clocked_in' => 'Clocked in now',
        'since' => 'since :time',
        'empty' => 'Nobody has clocked anything this week.',
    ],

    'errors' => [
        'in_the_future' => 'That moment has not happened yet.',
        'ends_before_it_starts' => 'The end time is before the start time.',
        'end_required' => 'This stretch is already closed; it cannot start running again. Begin a new one instead.',
        'overlaps' => 'You already have a stretch covering part of this. The same afternoon twice would be counted twice.',
    ],
];
