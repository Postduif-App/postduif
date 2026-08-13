<?php

use App\Actions\Chat\SendMessage;
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
