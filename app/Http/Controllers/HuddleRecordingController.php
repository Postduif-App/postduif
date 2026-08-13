<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SendMessage;
use App\Events\HuddleUpdated;
use App\Jobs\TranscribeHuddleRecording;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\HuddleRecording;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recording a huddle, and reading back what was said in it.
 *
 * The audio is made in the browser and arrives here when it stops. That is not
 * a shortcut: huddles in this application are peer-to-peer, so there is no
 * server in the middle holding a stream it could record — the only machine that
 * ever has the whole conversation is one of the participants'.
 *
 * Which means the person recording is a participant, and everybody else has to
 * be told. See announce() below, which is called when recording starts rather
 * than when it finishes: consent given after the fact is not consent.
 */
class HuddleRecordingController extends Controller
{
    private const BOT_NAME = 'Huddles';

    /** How large a recording may be, in kilobytes. Roughly two hours of Opus. */
    private const MAX_KILOBYTES = 120_000;

    /**
     * Say in the channel that this huddle is being recorded.
     *
     * Its own endpoint, called the moment somebody presses record, and the
     * reason it is not folded into the upload below: a recording that announced
     * itself when it finished would have recorded a conversation nobody knew
     * was being recorded. The message is the notice, and it is in the channel
     * rather than only in the huddle window so that somebody joining halfway
     * through can still see it.
     */
    public function announce(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Huddle $huddle,
        SendMessage $sendMessage,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('join', [Huddle::class, $channel]);
        abort_unless($huddle->channel_id === $channel->id, 404);

        /*
         * Only somebody who is in the room may say it is being recorded. The
         * notice names them, and a name in that sentence has to belong to
         * somebody the others can actually hear.
         */
        abort_unless($this->isPresent($huddle, $request->user()->id), 403);

        /*
         * One recorder at a time. Not because two would break anything on the
         * wire — each browser mixes its own copy — but because the indicator
         * names a person, and a notice that can only name one of two people
         * recording is a notice that is quietly wrong.
         */
        abort_if(
            $huddle->isBeingRecorded() && $huddle->recording_by !== $request->user()->id,
            409,
            __('huddles.recording.already'),
        );

        /*
         * Nothing to say twice. A browser that reconnects and announces again
         * would otherwise post the same sentence into the channel a second
         * time, which reads as a second recording.
         */
        if ($huddle->isBeingRecorded()) {
            return back();
        }

        $huddle->forceFill([
            'recording_by' => $request->user()->id,
            'recording_started_at' => now(),
        ])->save();

        $sendMessage->fromSystem(
            $channel,
            __('huddles.recording.started', ['name' => $request->user()->name]),
            self::BOT_NAME,
        );

        /*
         * And the live half of the notice: everybody already in the huddle sees
         * the indicator come on without waiting for a page to be fetched again.
         */
        HuddleUpdated::dispatch($huddle);

        return back();
    }

    /**
     * Take the notice down again.
     *
     * Its own call rather than something the upload does, because the upload is
     * not guaranteed to happen: a browser that stops recording and then fails
     * to send the file has still stopped recording, and leaving the indicator
     * lit would tell the channel a lie in the one direction that matters.
     *
     * Only the person who started it, and silently fine if somebody else
     * already cleared it — the recorder leaving does the same thing.
     */
    public function stopped(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Huddle $huddle,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        abort_unless($huddle->channel_id === $channel->id, 404);

        if ($huddle->recording_by !== $request->user()->id) {
            return back();
        }

        $huddle->stopRecording();

        HuddleUpdated::dispatch($huddle);

        return back();
    }

    /**
     * The audio, once the browser has stopped recording.
     *
     * Whoever was in the huddle may upload for it, not merely whoever may see
     * the channel: the file claims to be a recording of a conversation, and the
     * people who were in it are the only ones who could have made one.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Huddle $huddle,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('join', [Huddle::class, $channel]);
        abort_unless($huddle->channel_id === $channel->id, 404);

        abort_unless(
            $huddle->participants()->where('user_id', $request->user()->id)->exists(),
            403,
        );

        /*
         * The file arriving is proof that this browser has stopped, so the
         * notice comes down here too. Not instead of stopped() above — an
         * upload that never arrives has to clear it as well — but a browser
         * that got this far should not need a second round trip to be honest.
         */
        if ($huddle->recording_by === $request->user()->id) {
            $huddle->stopRecording();

            HuddleUpdated::dispatch($huddle);
        }

        $validated = $request->validate([
            'audio' => ['required', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,video/webm', 'max:'.self::MAX_KILOBYTES],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $recording = HuddleRecording::create([
            'huddle_id' => $huddle->id,
            'recorded_by' => $request->user()->id,
            'duration_seconds' => $validated['duration_seconds'] ?? null,
        ]);

        $recording->addMedia($request->file('audio'))
            ->toMediaCollection(HuddleRecording::AUDIO);

        /*
         * Queued rather than done here. A half-hour meeting takes minutes to
         * come back, and the browser that just uploaded it has nothing to wait
         * for — the channel is told when the words are in, which is the only
         * moment anybody cares about.
         */
        TranscribeHuddleRecording::dispatch($recording);

        return back();
    }

    /**
     * Playing one back.
     *
     * Guarded by the channel's own policy rather than by having been in the
     * huddle: a recording is the channel's record of a meeting, and somebody
     * who joined the channel afterwards is entitled to it the same way they are
     * entitled to everything else said there.
     *
     * Streamed off the private disk, never a public URL — this is the most
     * sensitive thing this application stores, and a link that worked without a
     * session would carry a private meeting out of the building.
     */
    public function show(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        HuddleRecording $recording,
    ): StreamedResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('view', $channel);
        abort_unless($recording->huddle?->channel_id === $channel->id, 404);

        $media = $recording->getFirstMedia(HuddleRecording::AUDIO);

        abort_if($media === null, 404);

        return $media->toResponse($request);
    }

    /** Whether this member is in the huddle now, rather than was at some point. */
    private function isPresent(Huddle $huddle, int $userId): bool
    {
        return $huddle->present()->where('user_id', $userId)->exists();
    }
}
