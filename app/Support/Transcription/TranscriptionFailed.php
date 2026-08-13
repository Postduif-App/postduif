<?php

namespace App\Support\Transcription;

use RuntimeException;

/**
 * The transcriber could not do it, with a sentence a person can read.
 *
 * Its own class rather than a bare RuntimeException so that the job can tell
 * "the service refused this file" apart from a bug in the code around it —
 * only the first is worth writing into transcription_error and showing to a
 * beheerder.
 */
class TranscriptionFailed extends RuntimeException {}
