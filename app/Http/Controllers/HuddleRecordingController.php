<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SendMessage;
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

        $sendMessage->fromSystem(
            $channel,
            __('huddles.recording.started', ['name' => $request->user()->name]),
            self::BOT_NAME,
        );

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
}
