<?php

namespace App\Support\Transcription;

/**
 * What a workspace gets when nobody has configured a transcription service.
 *
 * It refuses rather than returning an empty transcript, and that is the whole
 * point of having it: an installation with no service configured should have
 * recordings that are plainly not transcribed, with a sentence saying why —
 * not recordings that appear to have been transcribed into silence.
 */
class NullTranscriber implements Transcriber
{
    public function handle(string $path, ?string $language = null): string
    {
        throw new TranscriptionFailed(__('huddles.transcription.not_configured'));
    }
}
