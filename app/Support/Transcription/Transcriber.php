<?php

namespace App\Support\Transcription;

/**
 * Something that turns recorded audio into words.
 *
 * A contract with one method, and the reason it exists is the choice behind it:
 * transcription is the one part of this feature that cannot be done in the
 * application. Whoever runs a Postduif can point it at OpenAI, at a
 * whisper.cpp server on the same machine, or at nothing at all — and none of
 * those should be visible to the job that asks for a transcript.
 *
 * Implementations throw rather than returning an error string. The caller has
 * somewhere to put the message — see the transcription_error column — and a
 * method that returned "" for both silence and failure would be one nobody
 * could tell those apart in.
 */
interface Transcriber
{
    /**
     * @param  string  $path  An absolute path to an audio file on local disk.
     * @param  string|null  $language  A hint, in ISO 639-1. Null lets the
     *                                 service decide, which is what a workspace
     *                                 that speaks two languages wants.
     *
     * @throws TranscriptionFailed
     */
    public function handle(string $path, ?string $language = null): string;
}
