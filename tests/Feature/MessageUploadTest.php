<?php

use App\Enums\AttachmentType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A channel to post files into, with the workspace's settings to hand.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function channelTakingFiles(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

/** @param array<string, mixed> $payload */
function sendMessage(User $user, $workspace, $channel, array $payload = [])
{
    return actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => strtolower((string) Str::ulid()),
        ...$payload,
    ]);
}

it('sends a message that is nothing but a file', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel, [
        'attachments' => [UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf')],
    ])->assertRedirect();

    $message = Message::sole();

    expect($message->body)->toBe('')
        ->and($message->getMedia(Message::ATTACHMENTS))->toHaveCount(1)
        ->and($message->getFirstMedia(Message::ATTACHMENTS)->file_name)->toBe('plattegrond.pdf');
});

it('sends words and a file together', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel, [
        'body' => 'Hier is de plattegrond',
        'attachments' => [UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf')],
    ])->assertRedirect();

    expect(Message::sole())
        ->body->toBe('Hier is de plattegrond')
        ->and(Message::sole()->getMedia(Message::ATTACHMENTS))->toHaveCount(1);
});

it('still refuses a message that is neither words nor a file', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel)->assertSessionHasErrors('body');

    expect(Message::count())->toBe(0);
});

it('refuses a file larger than the workspace allows', function () {
    [$user, $workspace, $channel] = channelTakingFiles();
    $workspace->update(['max_attachment_kb' => 1024]);

    sendMessage($user, $workspace, $channel, [
        'attachments' => [UploadedFile::fake()->create('groot.pdf', 2048, 'application/pdf')],
    ])->assertSessionHasErrors('attachments.0');

    expect(Message::count())->toBe(0);
});

it('refuses a type the workspace does not take', function () {
    [$user, $workspace, $channel] = channelTakingFiles();
    $workspace->update(['allowed_attachment_types' => [AttachmentType::Images->value]]);

    sendMessage($user, $workspace, $channel, [
        'attachments' => [UploadedFile::fake()->create('notulen.pdf', 10, 'application/pdf')],
    ])->assertSessionHasErrors('attachments.0');

    expect(Message::count())->toBe(0);
});

it('refuses every file once sharing is switched off', function () {
    [$user, $workspace, $channel] = channelTakingFiles();
    $workspace->update(['uploads_enabled' => false]);

    sendMessage($user, $workspace, $channel, [
        'body' => 'Kijk eens',
        'attachments' => [UploadedFile::fake()->create('notulen.pdf', 10, 'application/pdf')],
    ])->assertSessionHasErrors('attachments');

    expect(Message::count())->toBe(0);
});

it('still takes a plain message once sharing is switched off', function () {
    [$user, $workspace, $channel] = channelTakingFiles();
    $workspace->update(['uploads_enabled' => false]);

    sendMessage($user, $workspace, $channel, ['body' => 'Gewoon tekst'])->assertRedirect();

    expect(Message::sole()->body)->toBe('Gewoon tekst');
});

it('refuses more files than fit in one message', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    $files = [];

    for ($i = 0; $i < 11; $i++) {
        $files[] = UploadedFile::fake()->create("bestand-{$i}.pdf", 5, 'application/pdf');
    }

    sendMessage($user, $workspace, $channel, ['attachments' => $files])
        ->assertSessionHasErrors('attachments');

    expect(Message::count())->toBe(0);
});

it('hands the browser a guarded URL for every attachment', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel, [
        'attachments' => [UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf')],
    ]);

    $media = Message::sole()->getFirstMedia(Message::ATTACHMENTS);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('messages.0.attachments', 1)
            ->where('messages.0.attachments.0.name', 'plattegrond.pdf')
            ->where('messages.0.attachments.0.mimeType', $media->mime_type)
            ->where('messages.0.attachments.0.size', $media->size)
            // Through the route that asks the channel, never a disk path.
            ->where('messages.0.attachments.0.url', route(
                'chat.messages.attachments.show',
                [$workspace, $channel, Message::sole(), $media],
            )));
});

/**
 * A deleted message only stays on screen when replies hang off it — otherwise
 * it drops out of the list entirely. The tombstone is the case worth checking:
 * it is the one place a deleted message still renders.
 */
it('shows no attachment on a message that was taken back', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel, [
        'attachments' => [UploadedFile::fake()->create('plattegrond.pdf', 20, 'application/pdf')],
    ]);

    $message = Message::sole();

    sendMessage($user, $workspace, $channel, [
        'body' => 'Dank je',
        'parent_id' => $message->id,
    ]);

    $message->delete();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.attachments', []));
});

it('tells the composer what this workspace takes', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.uploads.maxKb', 10240));

    $workspace->update(['uploads_enabled' => false]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.uploads', null));
});

/**
 * What the voice recorder produces: a webm the browser made, sent as an
 * ordinary attachment. Nothing about it is special once it exists — same
 * validation, same private disk, same guarded route.
 */
it('takes a recorded voice note like any other file', function () {
    [$user, $workspace, $channel] = channelTakingFiles();

    sendMessage($user, $workspace, $channel, [
        'attachments' => [
            UploadedFile::fake()->create('spraakbericht-1.webm', 40, 'audio/webm'),
        ],
    ])->assertRedirect();

    expect(Message::sole()->getFirstMedia(Message::ATTACHMENTS)->file_name)
        ->toBe('spraakbericht-1.webm');
});

it('refuses a voice note where the workspace does not take audio', function () {
    [$user, $workspace, $channel] = channelTakingFiles();
    $workspace->update(['allowed_attachment_types' => [AttachmentType::Images->value]]);

    sendMessage($user, $workspace, $channel, [
        'attachments' => [
            UploadedFile::fake()->create('spraakbericht-1.webm', 40, 'audio/webm'),
        ],
    ])->assertSessionHasErrors('attachments.0');
});
