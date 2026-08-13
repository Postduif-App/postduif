<?php

namespace App\Support\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Transcription over an OpenAI-compatible /audio/transcriptions endpoint.
 *
 * One implementation for what is in practice two very different deployments,
 * and deliberately so: OpenAI's own API and the self-hosted whisper servers
 * (whisper.cpp, faster-whisper) speak the same shape, so a workspace that will
 * not send audio out of the building points the base URL at localhost and
 * changes nothing else. That is the difference between "you can self-host this"
 * and "you can self-host everything except the interesting part".
 */
class WhisperTranscriber implements Transcriber
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function handle(string $path, ?string $language = null): string
    {
        $audio = file_get_contents($path);

        if ($audio === false) {
            // The file was there when the job was queued and is not now: the
            // disk filled, a sweep ran, somebody tidied. Worth a sentence in
            // the recording rather than a fatal further down.
            throw new TranscriptionFailed(__('huddles.transcription.unreadable'));
        }

        $request = Http::timeout($this->timeout)
            ->attach('file', $audio, basename($path));

        if ($this->token !== null) {
            $request = $request->withToken($this->token);
        }

        try {
            $response = $request->post(rtrim($this->baseUrl, '/').'/audio/transcriptions', array_filter([
                'model' => $this->model,
                'language' => $language,
                // Plain text back rather than the verbose JSON: what this
                // application stores is a transcript somebody reads, and the
                // word-level timings would be a much larger payload nothing
                // here has a use for yet.
                'response_format' => 'text',
            ]));
        } catch (ConnectionException $exception) {
            throw new TranscriptionFailed($exception->getMessage(), previous: $exception);
        } catch (Throwable $exception) {
            throw new TranscriptionFailed($exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            /*
             * The service's own words where there are any. A transcription that
             * failed because the file was too long and one that failed because
             * the key expired are the same HTTP status and completely different
             * problems, and only the body says which.
             */
            throw new TranscriptionFailed(
                (string) ($response->json('error.message') ?? $response->body() ?: $response->status()),
            );
        }

        return trim($response->body());
    }
}
