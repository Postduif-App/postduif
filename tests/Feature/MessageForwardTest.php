<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\actingAs;

/**
 * A message in one channel, and a second channel to carry it to.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel, 4: Message}
 */
function forwardableMessage(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $source = channelWithMember($workspace, $user);
    $target = channelWithMember($workspace, $user);

    $author = User::factory()->create(['name' => 'Anna Bakker']);
    $source->members()->attach($author->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $source->id,
        'user_id' => $author->id,
        'body' => 'De levering is verzet naar dinsdag',
    ]);

    return [$user, $workspace, $source, $target, $message];
}

/**
 * A file on a message, with the mime type it would really have.
 *
 * A fake upload is empty, and an empty file is detected as
 * "application/x-empty" — which no workspace allows, so the forward would skip
 * it for a reason that has nothing to do with what is being tested.
 */
function attachPdf(Message $message): Media
{
    $media = $message
        ->addMedia(UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf'))
        ->toMediaCollection(Message::ATTACHMENTS);

    $media->forceFill(['mime_type' => 'application/pdf'])->save();

    return $media;
}

it('carries a message into another channel', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertRedirect();

    $forwarded = $target->messages()->sole();

    expect($forwarded->body)->toBe('De levering is verzet naar dinsdag')
        // Attribution, not authorship: the forwarder placed it.
        ->and($forwarded->user_id)->toBe($user->id)
        ->and($forwarded->forwarded_from)->toBe('Anna Bakker')
        // And the original stays exactly where it was.
        ->and($source->messages()->count())->toBe(1);
});

it('puts a note above what was forwarded', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
        'note' => 'Even voor jullie ter info',
    ]);

    expect($target->messages()->sole()->body)
        ->toBe("Even voor jullie ter info\n\nDe levering is verzet naar dinsdag");
});

/**
 * Two permissions, and both are needed. Reading where it comes from does not
 * make somewhere else a place you may put things.
 */
it('refuses a target this member may not post in', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    $target->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertForbidden();

    expect($target->messages()->count())->toBe(0);
});

it('refuses a source this member may not read', function () {
    [, $workspace, $source, $target, $message] = forwardableMessage();

    $source->update(['type' => ChannelType::Private]);

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    $target->members()->attach($outsider->id, ['joined_at' => now()]);

    actingAs($outsider)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertForbidden();
});

it('does not reach a channel in another workspace', function () {
    [$user, $workspace, $source, , $message] = forwardableMessage();

    $elsewhere = Channel::factory()->create();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('channel_id');
});

/** Route binding leaves trashed messages out, so it never resolves at all. */
it('refuses to forward something that was taken back', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    $message->delete();

    actingAs($user)
        ->post(route('chat.messages.forward', [$workspace, $source, $message]), [
            'channel_id' => $target->id,
        ])
        ->assertNotFound();
});

it('keeps the bot name as the attribution', function () {
    [$user, $workspace, $source, $target] = forwardableMessage();

    $fromBot = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $source->id,
        'user_id' => null,
        'bot_name' => 'Statuspagina',
        'body' => 'Storing opgelost',
    ]);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $fromBot]), [
        'channel_id' => $target->id,
    ]);

    expect($target->messages()->sole()->forwarded_from)->toBe('Statuspagina');
});

it('carries the files along as copies', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    $original = attachPdf($message);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
    ]);

    $copy = $target->messages()->sole()->getFirstMedia(Message::ATTACHMENTS);

    expect($copy)->not->toBeNull()
        ->and($copy->file_name)->toBe('plattegrond.pdf')
        // A copy, not a second row pointing at the same bytes: the forward has
        // to keep working when the original message is taken back.
        ->and($copy->id)->not->toBe($original->id)
        ->and($copy->getPath())->not->toBe($original->getPath());
});

it('leaves the forward alone when the original is taken back', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    attachPdf($message);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
    ]);

    $copy = $target->messages()->sole()->getFirstMedia(Message::ATTACHMENTS);

    $message->forceDelete();

    expect(Media::whereKey($copy->id)->exists())->toBeTrue()
        ->and(is_file($copy->fresh()->getPath()))->toBeTrue();
});

/**
 * Judged against the workspace as it stands now: one that has since stopped
 * taking that kind of file means it now, and a forward is a new message.
 */
it('leaves a file behind that the workspace no longer takes', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    attachPdf($message);

    $workspace->update(['allowed_attachment_types' => ['images']]);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
    ]);

    $forwarded = $target->messages()->sole();

    // The words still go; only the file stays behind.
    expect($forwarded->body)->toBe('De levering is verzet naar dinsdag')
        ->and($forwarded->getMedia(Message::ATTACHMENTS))->toBeEmpty();
});

it('leaves the files behind when sharing is switched off altogether', function () {
    [$user, $workspace, $source, $target, $message] = forwardableMessage();

    attachPdf($message);

    $workspace->update(['uploads_enabled' => false]);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $source, $message]), [
        'channel_id' => $target->id,
    ]);

    expect($target->messages()->sole()->getMedia(Message::ATTACHMENTS))->toBeEmpty();
});
