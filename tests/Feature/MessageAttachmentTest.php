<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\actingAs;

/**
 * A message in a private channel with a file on it.
 *
 * Private on purpose: the point of serving attachments through a route is that
 * the channel's own rules decide who gets the file, and a public channel would
 * not tell those two apart.
 *
 * @return array{0: User, 1: Channel, 2: Message, 3: Media}
 */
function messageWithAttachment(): array
{
    $author = User::factory()->create();
    $workspace = workspaceWithMember($author);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    $media = $message
        ->addMedia(UploadedFile::fake()->create('notulen.pdf', 12, 'application/pdf'))
        ->toMediaCollection(Message::ATTACHMENTS);

    return [$author, $channel, $message, $media];
}

function attachmentUrl(Channel $channel, Message $message, Media $media): string
{
    return route('chat.messages.attachments.show', [
        $channel->workspace,
        $channel,
        $message,
        $media,
    ]);
}

it('hands the file to somebody in the channel', function () {
    [$author, $channel, $message, $media] = messageWithAttachment();

    actingAs($author)
        ->get(attachmentUrl($channel, $message, $media))
        ->assertOk()
        // The type recorded at upload, not one guessed again from the bytes:
        // that guess is what a file response reaches for on its own, and it
        // answers "application/x-empty" for anything it cannot read.
        ->assertHeader('Content-Type', $media->mime_type)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

/**
 * The route sits on the application's own origin, so an uploaded page served
 * inline would run its script as us. Anything that is not an image, a video or
 * a PDF is handed over as a download instead.
 */
it('never renders an uploaded page in place', function (string $type, string $disposition) {
    [$author, $channel, $message] = messageWithAttachment();

    /*
     * Uploaded as a plain file and then given the type under test. What the
     * controller decides on is the recorded mime type, so this isolates that
     * one decision — and keeps image conversions out of a test that is not
     * about them.
     */
    $media = $message
        ->addMedia(UploadedFile::fake()->createWithContent('bijlage.txt', '<script>alert(1)</script>'))
        ->toMediaCollection(Message::ATTACHMENTS);

    $media->forceFill(['mime_type' => $type])->save();

    $response = actingAs($author)
        ->get(attachmentUrl($channel, $message, $media))
        ->assertOk()
        ->assertHeader('Content-Disposition', $disposition.'; filename="bijlage.txt"');

    // Loosely: a text type comes back with a charset appended to it.
    expect($response->headers->get('Content-Type'))->toStartWith($type);
})->with([
    'html' => ['text/html', 'attachment'],
    'svg' => ['image/svg+xml', 'attachment'],
    'xml' => ['application/xml', 'attachment'],
    'image' => ['image/png', 'inline'],
    'video' => ['video/mp4', 'inline'],
    'pdf' => ['application/pdf', 'inline'],
]);

it('refuses somebody who cannot see the channel', function () {
    [, $channel, $message, $media] = messageWithAttachment();

    $outsider = User::factory()->create();
    $channel->workspace->members()->attach($outsider->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($outsider)
        ->get(attachmentUrl($channel, $message, $media))
        ->assertForbidden();
});

it('is not reachable at all while signed out', function () {
    [, $channel, $message, $media] = messageWithAttachment();

    $this->get(attachmentUrl($channel, $message, $media))->assertRedirect(route('login'));
});

it('does not serve a file that belongs to another message', function () {
    [$author, $channel, , $media] = messageWithAttachment();

    $elsewhere = Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
    ]);

    actingAs($author)
        ->get(attachmentUrl($channel, $elsewhere, $media))
        ->assertNotFound();
});

it('keeps the file off any disk that serves itself', function () {
    [, , , $media] = messageWithAttachment();

    expect($media->disk)->toBe('local')
        ->and(config('media-library.disk_name'))->toBe('local');
});

it('takes the file with the message when it is really gone', function () {
    [, , $message, $media] = messageWithAttachment();

    // Soft delete first: the message can still come back, so the file stays.
    $message->delete();

    expect(Media::whereKey($media->id)->exists())->toBeTrue();

    $message->forceDelete();

    expect(Media::whereKey($media->id)->exists())->toBeFalse();
});

it('lets the author take one file back', function () {
    [$author, $channel, $message, $media] = messageWithAttachment();

    $message->update(['body' => 'Kijk hier']);

    actingAs($author)
        ->delete(attachmentUrl($channel, $message, $media))
        ->assertRedirect();

    expect(Media::whereKey($media->id)->exists())->toBeFalse()
        // The words stay: only the file was taken back.
        ->and($message->fresh()->trashed())->toBeFalse();
});

/**
 * A message that was nothing but this file has nothing left to be, so it goes
 * too — an empty line in a conversation is not a message.
 */
it('takes the message with it when the file was all there was', function () {
    [$author, $channel, $message, $media] = messageWithAttachment();

    $message->update(['body' => '']);

    actingAs($author)->delete(attachmentUrl($channel, $message, $media));

    expect(Media::whereKey($media->id)->exists())->toBeFalse()
        ->and($message->fresh()->trashed())->toBeTrue();
});

it('leaves a message alone while it still has another file', function () {
    [$author, $channel, $message] = messageWithAttachment();

    $message->update(['body' => '']);

    $second = $message
        ->addMedia(UploadedFile::fake()->create('tweede.pdf', 10, 'application/pdf'))
        ->toMediaCollection(Message::ATTACHMENTS);

    actingAs($author)->delete(attachmentUrl($channel, $message, $second));

    expect($message->fresh()->trashed())->toBeFalse()
        ->and($message->fresh()->getMedia(Message::ATTACHMENTS))->toHaveCount(1);
});

it('refuses somebody else in the channel', function () {
    [, $channel, $message, $media] = messageWithAttachment();

    $other = User::factory()->create();
    $channel->workspace->members()->attach($other->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    actingAs($other)
        ->delete(attachmentUrl($channel, $message, $media))
        ->assertForbidden();

    expect(Media::whereKey($media->id)->exists())->toBeTrue();
});

it('hands over a download when one is asked for', function () {
    [$author, $channel, $message, $media] = messageWithAttachment();

    // A PDF is shown in place by default, so it is the case where asking makes
    // a difference.
    actingAs($author)
        ->get(attachmentUrl($channel, $message, $media).'?download=1')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="notulen.pdf"');
});

it('does not let a download request become a way to render something', function () {
    [$author, $channel, $message] = messageWithAttachment();

    $media = $message
        ->addMedia(UploadedFile::fake()->createWithContent('bijlage.txt', '<script>alert(1)</script>'))
        ->toMediaCollection(Message::ATTACHMENTS);

    $media->forceFill(['mime_type' => 'text/html'])->save();

    // download=0 is not a request to show it: the allowlist still decides.
    actingAs($author)
        ->get(attachmentUrl($channel, $message, $media).'?download=0')
        ->assertHeader('Content-Disposition', 'attachment; filename="bijlage.txt"');
});
