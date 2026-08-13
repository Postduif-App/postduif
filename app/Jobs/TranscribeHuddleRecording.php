<?php

namespace App\Jobs;

use App\Actions\Chat\SendMessage;
use App\Models\HuddleRecording;
use App\Support\Transcription\Transcriber;
use App\Support\Transcription\TranscriptionFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Turn a recorded huddle into words, and say in the channel that it is ready.
 *
 * Queued, because a half-hour meeting takes minutes to come back and no request
 * should be holding a connection open for that. Not retried by default either:
 * a transcription that failed because the file is an hour long or because the
 * service refuses the format will fail the same way three more times, and each
 * attempt sends the whole recording again.
 */
class TranscribeHuddleRecording implements ShouldQueue
{
    use Queueable;

    /**
     * The name the announcement posts under — the same voice scheduled huddles
     * announce in, because to a reader it is the same subject.
     */
    private const BOT_NAME = 'Huddles';

    public int $tries = 1;

    public function __construct(private readonly HuddleRecording $recording) {}

    public function handle(Transcriber $transcriber, SendMessage $sendMessage): void
    {
        $media = $this->recording->getFirstMedia(HuddleRecording::AUDIO);

        if ($media === null) {
            return;
        }

        try {
            /*
             * A local copy, because the transcriber takes a path and the disk
             * behind medialibrary may not be one — an S3-backed installation
             * has no local file to hand over. Removed again below whatever
             * happens, or a busy workspace fills its temp directory with
             * meetings.
             */
            $local = $this->copyLocally($media->getPath(), $media->file_name);

            $transcript = $transcriber->handle($local);
        } catch (TranscriptionFailed $exception) {
            /*
             * Written down rather than thrown on. "Did this ever work" is the
             * question a beheerder actually has, and an exception that only
             * ever reached the failed-jobs table cannot answer it — the same
             * reasoning as last_error on the mail settings.
             */
            $this->recording->forceFill([
                'transcription_error' => Str::limit($exception->getMessage(), 1000),
            ])->save();

            return;
        } finally {
            if (isset($local) && is_file($local)) {
                unlink($local);
            }
        }

        $this->recording->forceFill([
            'transcript' => $transcript,
            'transcribed_at' => now(),
            // Cleared: a recording that worked on the second go should not
            // still be showing why the first one did not.
            'transcription_error' => null,
        ])->save();

        $channel = $this->recording->huddle?->channel;

        if ($channel === null || $channel->archived_at !== null) {
            return;
        }

        $sendMessage->fromSystem(
            $channel,
            __('huddles.transcription.ready', [
                'excerpt' => Str::limit($transcript, 280),
            ]),
            self::BOT_NAME,
        );
    }

    /** A copy on local disk, for a transcriber that takes a path. */
    private function copyLocally(string $path, string $name): string
    {
        $local = tempnam(sys_get_temp_dir(), 'huddle-').'-'.$name;

        copy($path, $local);

        return $local;
    }
}
