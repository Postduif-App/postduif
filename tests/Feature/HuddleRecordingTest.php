<?php

use App\Actions\Chat\SendMessage;
use App\Actions\Huddles\SweepStaleHuddles;
use App\Enums\ChannelType;
use App\Features\Huddles as HuddlesFeature;
use App\Jobs\TranscribeHuddleRecording;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\HuddleRecording;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Transcription\NullTranscriber;
use App\Support\Transcription\Transcriber;
use App\Support\Transcription\TranscriptionFailed;
use App\Support\Transcription\WhisperTranscriber;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A live huddle with one person in it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Huddle}
 */
function huddleRecordingFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    Feature::for($workspace)->activate(HuddlesFeature::class);

    $channel = channelWithMember($workspace, $user);
    $huddle = Huddle::factory()->create([
        'channel_id' => $channel->id,
        'started_by' => $user->id,
    ]);

    $huddle->participants()->create([
        'user_id' => $user->id,
        'joined_at' => now(),
    ]);

    return [$user, $workspace, $channel, $huddle];
}

/** A small file that passes the mimetype rule. */
function recordedAudio(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('huddle.webm', 'audio-bytes')
        ->mimeType('audio/webm');
}

it('tells the channel that recording has started, before anything is recorded', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]))
        ->assertRedirect();

    // Consent given after the fact is not consent, which is why this is its own
    // endpoint rather than something the upload does on arrival.
    expect(Message::query()->sole()->body)->toContain($user->name)
        ->and(Message::query()->sole()->bot_name)->toBe('Huddles');
});

it('marks the huddle as being recorded, so everybody in it can see so', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]))
        ->assertRedirect();

    // The message in the channel says a recording happened; this says one is
    // happening — which is the only version that can still change what somebody
    // says next.
    expect($huddle->fresh()->recording_by)->toBe($user->id)
        ->and($huddle->fresh()->isBeingRecorded())->toBeTrue();
});

it('says it once, however often a browser announces', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));
    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]))
        ->assertRedirect();

    // A browser that reconnects and announces again would otherwise read as a
    // second recording.
    expect(Message::query()->count())->toBe(1);
});

it('refuses a second recorder while one is already going', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);
    $huddle->participants()->create(['user_id' => $other->id, 'joined_at' => now()]);

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    // Two recordings would work on the wire; the notice names one person, and
    // a second recording nobody was told about is what the notice exists for.
    actingAs($other)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]))
        ->assertStatus(409);

    expect($huddle->fresh()->recording_by)->toBe($user->id);
});

it('refuses to announce for somebody who is not in the room', function () {
    [, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $bystander = User::factory()->create();
    joinWorkspace($workspace, $bystander);
    $channel->members()->attach($bystander->id, ['joined_at' => now()]);

    // The notice names them, and a name in that sentence has to belong to
    // somebody the others can actually hear.
    actingAs($bystander)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]))
        ->assertForbidden();

    expect($huddle->fresh()->isBeingRecorded())->toBeFalse();
});

it('takes the notice down when the browser says it has stopped', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    // Its own call rather than something the upload does: a browser that stops
    // and then fails to send the file has still stopped.
    actingAs($user)->delete(route('chat.huddles.recording.stopped', [$workspace, $channel, $huddle]))
        ->assertRedirect();

    expect($huddle->fresh()->isBeingRecorded())->toBeFalse();
});

it('leaves somebody else\'s notice alone', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    actingAs($other)->delete(route('chat.huddles.recording.stopped', [$workspace, $channel, $huddle]));

    expect($huddle->fresh()->recording_by)->toBe($user->id);
});

it('takes the audio and queues the transcription', function () {
    Queue::fake();

    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.store', [$workspace, $channel, $huddle]), [
        'audio' => recordedAudio(),
        'duration_seconds' => 420,
    ])->assertRedirect();

    $recording = HuddleRecording::query()->sole();

    expect($recording->huddle_id)->toBe($huddle->id)
        ->and($recording->duration_seconds)->toBe(420)
        ->and($recording->getFirstMedia(HuddleRecording::AUDIO))->not->toBeNull()
        // Nothing is transcribed in the request: a half-hour meeting takes
        // minutes to come back and the browser has nothing to wait for.
        ->and($recording->isTranscribed())->toBeFalse();

    Queue::assertPushed(TranscribeHuddleRecording::class);
});

it('puts the notice out when the file arrives', function () {
    Queue::fake();

    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    actingAs($user)->post(route('chat.huddles.recording.store', [$workspace, $channel, $huddle]), [
        'audio' => recordedAudio(),
    ])->assertRedirect();

    // A browser that got this far should not need a second round trip to stop
    // telling the channel it is recording.
    expect($huddle->fresh()->isBeingRecorded())->toBeFalse();
});

it('stops recording when the recorder walks out', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    actingAs($user)->delete(route('chat.huddles.destroy', [$workspace, $channel, $huddle]))
        ->assertRedirect();

    // Their browser held the only copy of the mix, so nothing is being recorded
    // the moment they are out of the room.
    expect($huddle->fresh()->isBeingRecorded())->toBeFalse();
});

it('stops recording for a recorder whose browser simply went quiet', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);
    $huddle->participants()->create([
        'user_id' => $other->id,
        'joined_at' => now(),
        'last_seen_at' => now(),
    ]);

    actingAs($user)->post(route('chat.huddles.recording.announce', [$workspace, $channel, $huddle]));

    // The recorder crashed; the other person is still talking. Without the
    // sweeper the indicator would stay lit for a recording that stopped when
    // the laptop did.
    $huddle->present()->where('user_id', $user->id)->update([
        'last_seen_at' => now()->subSeconds(SweepStaleHuddles::AFTER_SECONDS + 10),
    ]);

    (new SweepStaleHuddles)->handle();

    expect($huddle->fresh()->isBeingRecorded())->toBeFalse()
        ->and($huddle->fresh()->isLive())->toBeTrue();
});

it('refuses a recording from somebody who was not in the huddle', function () {
    [, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $bystander = User::factory()->create();
    joinWorkspace($workspace, $bystander);
    $channel->members()->attach($bystander->id, ['joined_at' => now()]);

    // Seeing the channel is not the same as having been in the room: only the
    // people who were there could have made a recording of it.
    actingAs($bystander)->post(route('chat.huddles.recording.store', [$workspace, $channel, $huddle]), [
        'audio' => recordedAudio(),
    ])->assertForbidden();

    expect(HuddleRecording::query()->count())->toBe(0);
});

it('writes the words down and says so in the channel', function () {
    [$user, , $channel, $huddle] = huddleRecordingFixture();

    $recording = HuddleRecording::factory()->create([
        'huddle_id' => $huddle->id,
        'recorded_by' => $user->id,
    ]);

    $recording->addMedia(recordedAudio())->toMediaCollection(HuddleRecording::AUDIO);

    app()->instance(Transcriber::class, new class implements Transcriber
    {
        public function handle(string $path, ?string $language = null): string
        {
            return 'We doen het volgende week.';
        }
    });

    (new TranscribeHuddleRecording($recording))->handle(app(Transcriber::class), app(SendMessage::class));

    expect($recording->fresh()->transcript)->toBe('We doen het volgende week.')
        ->and($recording->fresh()->isTranscribed())->toBeTrue()
        ->and($channel->messages()->where('bot_name', 'Huddles')->count())->toBe(1);
});

it('writes down why it failed rather than losing the reason', function () {
    [$user, , $channel, $huddle] = huddleRecordingFixture();

    $recording = HuddleRecording::factory()->create([
        'huddle_id' => $huddle->id,
        'recorded_by' => $user->id,
    ]);

    $recording->addMedia(recordedAudio())->toMediaCollection(HuddleRecording::AUDIO);

    (new TranscribeHuddleRecording($recording))->handle(new NullTranscriber, app(SendMessage::class));

    // "Did this ever work" is the question somebody actually has, and an
    // exception that only reached the failed-jobs table cannot answer it.
    expect($recording->fresh()->transcription_error)
        ->toBe(__('huddles.transcription.not_configured'))
        ->and($recording->fresh()->isTranscribed())->toBeFalse()
        // Nothing announced: there is nothing to read.
        ->and($channel->messages()->count())->toBe(0);
});

it('falls back to the transcriber that refuses when nothing is configured', function () {
    config()->set('services.transcription.url', null);

    expect(app(Transcriber::class))->toBeInstanceOf(NullTranscriber::class);

    config()->set('services.transcription.url', 'https://whisper.test/v1');

    expect(app(Transcriber::class))->toBeInstanceOf(WhisperTranscriber::class);
});

it('posts the audio to an OpenAI-compatible endpoint', function () {
    Http::fake(['whisper.test/*' => Http::response('Dit is de tekst.')]);

    $transcriber = new WhisperTranscriber('https://whisper.test/v1', 'geheim', 'whisper-1', 30);

    $file = tempnam(sys_get_temp_dir(), 'test-').'.webm';
    file_put_contents($file, 'audio-bytes');

    expect($transcriber->handle($file))->toBe('Dit is de tekst.');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://whisper.test/v1/audio/transcriptions'
        && $request->hasHeader('Authorization', 'Bearer geheim'));

    unlink($file);
});

it('turns a refusal from the service into a sentence a person can read', function () {
    Http::fake(['whisper.test/*' => Http::response(['error' => ['message' => 'File is too large']], 413)]);

    $transcriber = new WhisperTranscriber('https://whisper.test/v1', null, 'whisper-1', 30);

    $file = tempnam(sys_get_temp_dir(), 'test-').'.webm';
    file_put_contents($file, 'audio-bytes');

    // The service's own words: "too large" and "key expired" are the same
    // status code and completely different problems.
    expect(fn () => $transcriber->handle($file))
        ->toThrow(TranscriptionFailed::class, 'File is too large');

    unlink($file);
});

it('lets a channel member play a recording back, and nobody else', function () {
    [$user, $workspace, $channel, $huddle] = huddleRecordingFixture();

    $recording = HuddleRecording::factory()->create(['huddle_id' => $huddle->id]);
    $recording->addMedia(recordedAudio())->toMediaCollection(HuddleRecording::AUDIO);

    actingAs($user)->get(route('chat.huddles.recording.show', [$workspace, $channel, $recording]))
        ->assertOk();

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider);
    $channel->update(['type' => ChannelType::Private]);

    actingAs($outsider)->get(route('chat.huddles.recording.show', [$workspace, $channel, $recording]))
        ->assertForbidden();
});
