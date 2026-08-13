<?php

/*
 * What the channel reads about a huddle: that it is being recorded, and that it
 * has been written out.
 *
 * These lines are in the channel on purpose rather than only in the huddle
 * window. Somebody joining halfway through still has to be able to see that
 * recording is going on, and somebody who was not there has to be able to find
 * out afterwards that it happened.
 */
return [
    'recording' => [
        'started' => ':name has started recording this conversation.',
    ],

    'transcription' => [
        'ready' => "The conversation has been written out:\n\n:excerpt",
        'not_configured' => 'No transcription service has been set up for this installation.',
        'unreadable' => 'The recording could no longer be read when we came to write it out.',
    ],
];
