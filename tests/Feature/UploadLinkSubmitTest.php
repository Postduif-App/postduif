<?php

use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Notifications\FilesReceived;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

/**
 * What a customer fills in: their name and at least one file.
 *
 * @return array<string, mixed>
 */
function submissionPayload(array $overrides = []): array
{
    return [
        'files' => [UploadedFile::fake()->create('balans.pdf', 40)],
        'name' => 'Anna de Vries',
        ...$overrides,
    ];
}

it('shows somebody without an account what is being asked of them', function () {
    [$link] = openUploadLink(['message' => 'Graag de balans.']);

    get(route('upload-links.show', $link->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('upload-links/show')
            ->where('uploadLink.title', 'Jaarstukken 2025')
            ->where('uploadLink.message', 'Graag de balans.')
            ->where('uploadLink.ownerName', 'Sanne')
            ->where('uploadLink.state', 'usable')
            ->where('uploadLink.isLocked', false)
            ->where('uploadLink.uploadsLeft', null)
        );
});

it('says nothing at all about a token nobody recognises', function () {
    openUploadLink();

    get(route('upload-links.show', str_repeat('x', 64)))->assertNotFound();
    post(route('upload-links.submit', str_repeat('x', 64)), submissionPayload())->assertNotFound();
});

it('says which of the three reasons it stopped taking files', function (array $state, string $expected) {
    [$link] = openUploadLink($state);

    get(route('upload-links.show', $link->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('uploadLink.state', $expected));
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'expired'],
    'closed' => [['revoked_at' => now()->subHour()], 'revoked'],
    'full' => [['max_uploads' => 1, 'uploads' => 1], 'exhausted'],
]);

it('takes in what somebody sends, with their name on it', function () {
    Notification::fake();
    [$link] = openUploadLink(['max_uploads' => 2]);

    post(route('upload-links.submit', $link->token), submissionPayload([
        'files' => [
            UploadedFile::fake()->create('balans.pdf', 40),
            UploadedFile::fake()->create('resultaat.xlsx', 20),
        ],
        'email' => 'anna@klant.nl',
        'note' => 'De bijlagen volgen morgen.',
    ]))->assertRedirect();

    $submission = UploadLinkSubmission::sole();

    expect($submission)
        ->upload_link_id->toBe($link->id)
        ->name->toBe('Anna de Vries')
        ->email->toBe('anna@klant.nl')
        ->note->toBe('De bijlagen volgen morgen.')
        ->and($submission->files())->toHaveCount(2)
        ->and($link->refresh()->uploads)->toBe(1);
});

it('wants to know who it is from', function () {
    [$link] = openUploadLink();

    post(route('upload-links.submit', $link->token), submissionPayload(['name' => '']))
        ->assertSessionHasErrors('name');

    expect(UploadLinkSubmission::count())->toBe(0);
});

it('refuses a submission with nothing in it', function () {
    [$link] = openUploadLink();

    post(route('upload-links.submit', $link->token), ['name' => 'Anna'])
        ->assertSessionHasErrors('files');
});

it('takes nothing in once the link has closed', function (array $state) {
    [$link] = openUploadLink($state);

    post(route('upload-links.submit', $link->token), submissionPayload())
        ->assertSessionHasErrors('files');

    expect(UploadLinkSubmission::count())->toBe(0);
})->with([
    'expired' => [['expires_at' => now()->subDay()]],
    'closed' => [['revoked_at' => now()->subHour()]],
    'full' => [['max_uploads' => 1, 'uploads' => 1]],
]);

/**
 * The ceiling is on everything the link has taken in, not per submission —
 * otherwise an open link would be a way to fill the disk fifty files at a time.
 */
it('stops taking files once the link holds what the workspace allows', function () {
    [$link, $workspace] = openUploadLink();
    $workspace->update(['max_transfer_kb' => 100]);

    // Real bytes rather than a reported size: the ceiling is checked against
    // what is on disk, and fake()->create() writes nothing there.
    post(route('upload-links.submit', $link->token), submissionPayload([
        'files' => [UploadedFile::fake()->createWithContent('eerste.pdf', str_repeat('a', 70 * 1024))],
    ]))->assertSessionHasNoErrors();

    post(route('upload-links.submit', $link->token), submissionPayload([
        'files' => [UploadedFile::fake()->create('tweede.pdf', 70)],
    ]))->assertSessionHasErrors('files');

    expect(UploadLinkSubmission::count())->toBe(1);
});

it('asks for the password before anything else', function () {
    [$link] = openUploadLink(['message' => 'Graag de loonstroken van maart.']);
    $link->update(['password' => bcrypt('geheim123')]);

    get(route('upload-links.show', $link->token))
        ->assertInertia(fn ($page) => $page
            ->where('uploadLink.isLocked', true)
            ->where('uploadLink.message', null)
        );

    post(route('upload-links.submit', $link->token), submissionPayload())->assertForbidden();

    expect(UploadLinkSubmission::count())->toBe(0);
});

it('lets somebody in who knows the password', function () {
    [$link] = openUploadLink();
    $link->update(['password' => bcrypt('geheim123')]);

    post(route('upload-links.unlock', $link->token), ['password' => 'fout'])
        ->assertSessionHasErrors('password');

    post(route('upload-links.unlock', $link->token), ['password' => 'geheim123'])
        ->assertSessionHasNoErrors();

    post(route('upload-links.submit', $link->token), submissionPayload())->assertRedirect();

    expect(UploadLinkSubmission::count())->toBe(1);
});

it('does not carry being let into one link over to another', function () {
    [$link] = openUploadLink();
    $link->update(['password' => bcrypt('geheim123')]);
    $other = UploadLink::factory()->locked()->create(['workspace_id' => $link->workspace_id]);

    withSession([$link->unlockedSessionKey() => true])
        ->post(route('upload-links.submit', $other->token), submissionPayload())
        ->assertForbidden();
});

it('tells the owner in their channel, their inbox and their mail', function () {
    Notification::fake();
    [$link, $workspace, $owner] = openUploadLink();
    $channel = channelWithMember($workspace, $owner);
    $link->update(['notify_channel_id' => $channel->id]);

    post(route('upload-links.submit', $link->token), submissionPayload())->assertRedirect();

    $message = Message::query()->where('channel_id', $channel->id)->sole();

    expect($message->body)->toContain('Anna de Vries')
        ->toContain('Jaarstukken 2025')
        ->and($message->bot_name)->toBe('Bestanden');

    $item = InboxItem::query()->where('user_id', $owner->id)->sole();

    expect($item->type)->toBe(InboxItemType::UploadReceived)
        ->and($item->message_id)->toBe($message->id)
        ->and($item->read_at)->toBeNull();

    Notification::assertSentTo($owner, FilesReceived::class);
});

it('still mails the owner when no channel was named', function () {
    Notification::fake();
    [$link, , $owner] = openUploadLink();

    post(route('upload-links.submit', $link->token), submissionPayload())->assertRedirect();

    expect(Message::count())->toBe(0)
        ->and(InboxItem::count())->toBe(0);

    Notification::assertSentTo($owner, FilesReceived::class);
});

it('says nothing in a channel that has since been archived', function () {
    Notification::fake();
    [$link, $workspace, $owner] = openUploadLink();
    $channel = channelWithMember($workspace, $owner);
    $channel->forceFill(['archived_at' => now()])->save();
    $link->update(['notify_channel_id' => $channel->id]);

    post(route('upload-links.submit', $link->token), submissionPayload())->assertRedirect();

    expect(Message::query()->where('bot_name', 'Bestanden')->count())->toBe(0);
    Notification::assertSentTo($owner, FilesReceived::class);
});
