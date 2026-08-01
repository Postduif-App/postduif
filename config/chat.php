<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Actieve threads
    |--------------------------------------------------------------------------
    |
    | How long a thread stays in the sidebar after its last reply. Config rather
    | than a column: it is one number for the whole installation, and turning it
    | into a per-workspace setting later is a migration, not a rewrite.
    |
    */

    'thread_window_hours' => (int) env('CHAT_THREAD_WINDOW_HOURS', 24),

    /**
     * Upper bound on what the sidebar shows. A very busy workspace should not
     * hand the browser hundreds of rows for a section you have to scroll past
     * to reach your channels.
     */
    'thread_limit' => 25,

];
