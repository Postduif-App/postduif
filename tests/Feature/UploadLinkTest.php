<?php

use App\Actions\Transfers\PruneTransfers;
use App\Enums\SystemRole;
use App\Features\Transfers;
use App\Models\Channel;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * The smallest request the endpoint takes.
 *
 * @return array<string, mixed>
 */
function uploadLinkPayload(array $overrides = []): array
{
    return [
        'title' => 'Jaarstukken 2025',
        'valid_for_days' => 14,
        ...$overrides,
    ];
}

/**
 * Something a customer already sent in, with a real file on the fake disk.
 *
 * @return array{0: UploadLinkSubmission, 1: Media}
 */
function receivedSubmission(UploadLink $link, string $file = 'balans.pdf'): array
{
    $submission = UploadLinkSubmission::factory()->create([
        'upload_link_id' => $link->id,
        'name' => 'Anna de Vries',
    ]);

    $media = $submission->addMedia(UploadedFile::fake()->createWithContent($file, 'inhoud'))
        ->toMediaCollection(UploadLinkSubmission::FILES);

    return [$submission, $media];
}

it('puts a link out that somebody can send files in through', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload([
            'message' => 'Graag de balans en de winst-en-verliesrekening.',
            'max_uploads' => 3,
            'password' => 'geheim123',
        ]))
        ->assertRedirect();

    $link = UploadLink::sole();

    expect($link)
        ->workspace_id->toBe($workspace->id)
        ->created_by->toBe($user->id)
        ->title->toBe('Jaarstukken 2025')
        ->max_uploads->toBe(3)
        ->uploads->toBe(0)
        ->isUsable()->toBeTrue()
        ->and(strlen($link->token))->toBe(64)
        ->and(Hash::check('geheim123', $link->password))->toBeTrue()
        ->and($link->expires_at->isSameDay(now()->addDays(14)))->toBeTrue();
});

it('insists on a title, because there are no files yet to say what it is for', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload(['title' => '']))
        ->assertSessionHasErrors('title');

    expect(UploadLink::count())->toBe(0);
});

it('will not keep a link open longer than the workspace allows', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['max_transfer_days' => 7]);

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload(['valid_for_days' => 30]))
        ->assertSessionHasErrors('valid_for_days');
});

it('remembers which channel to tell when something comes in', function () {
    [$user, $workspace] = senderInWorkspace();
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload([
            'notify_channel_id' => $channel->id,
        ]))
        ->assertRedirect();

    expect(UploadLink::sole()->notify_channel_id)->toBe($channel->id);
});

it('refuses a channel from another workspace', function () {
    [$user, $workspace] = senderInWorkspace();
    $elsewhere = Channel::factory()->create(['workspace_id' => Workspace::factory()]);

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload([
            'notify_channel_id' => $elsewhere->id,
        ]))
        ->assertSessionHasErrors('notify_channel_id');

    expect(UploadLink::count())->toBe(0);
});

it('does not exist where the workspace switched transfers off', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload())
        ->assertNotFound();
});

it('does not let a guest put a door into the workspace', function () {
    [$user, $workspace] = senderInWorkspace(SystemRole::Guest);

    actingAs($user)
        ->post(route('chat.upload-links.store', $workspace), uploadLinkPayload())
        ->assertForbidden();

    expect(UploadLink::count())->toBe(0);
});

it('closes a link without losing what came in', function () {
    [$link, $workspace, $owner] = openUploadLink();
    receivedSubmission($link);

    actingAs($owner)
        ->delete(route('chat.upload-links.destroy', [$workspace, $link]))
        ->assertRedirect();

    expect($link->refresh()->isRevoked())->toBeTrue()
        ->and($link->submissions()->count())->toBe(1);
});

it('does not let a colleague close somebody else\'s link', function () {
    [$link, $workspace] = openUploadLink();
    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);

    actingAs($colleague)
        ->delete(route('chat.upload-links.destroy', [$workspace, $link]))
        ->assertForbidden();

    expect($link->refresh()->isRevoked())->toBeFalse();
});

it('hands what came in to the owner as an attachment, never inline', function () {
    [$link, $workspace, $owner] = openUploadLink();
    [, $media] = receivedSubmission($link, 'factuur.html');

    $response = actingAs($owner)
        ->get(route('chat.upload-links.files.download', [$workspace, $link, $media->id]))
        ->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('lets whoever runs the workspace fetch it too', function () {
    [$link, $workspace] = openUploadLink();
    [, $media] = receivedSubmission($link);
    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    actingAs($admin)
        ->get(route('chat.upload-links.files.download', [$workspace, $link, $media->id]))
        ->assertOk();
});

it('keeps what a customer sent from the rest of the workspace', function () {
    [$link, $workspace] = openUploadLink();
    [, $media] = receivedSubmission($link);
    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);

    actingAs($colleague)
        ->get(route('chat.upload-links.files.download', [$workspace, $link, $media->id]))
        ->assertForbidden();
});

it('will not hand over a file that belongs to another link', function () {
    [$link, $workspace, $owner] = openUploadLink();
    $other = UploadLink::factory()->create(['workspace_id' => $workspace->id, 'created_by' => $owner->id]);
    [, $media] = receivedSubmission($other);

    actingAs($owner)
        ->get(route('chat.upload-links.files.download', [$workspace, $link, $media->id]))
        ->assertNotFound();
});

it('hands over one person\'s files as a single archive', function () {
    [$link, $workspace, $owner] = openUploadLink();
    [$submission] = receivedSubmission($link);

    actingAs($owner)
        ->get(route('chat.upload-links.submissions.download', [$workspace, $link, $submission]))
        ->assertOk()
        ->assertDownload();
});

it('lists the owner\'s links and what came in, beside the transfers', function () {
    [$link, $workspace, $owner] = openUploadLink();
    receivedSubmission($link);

    actingAs($owner)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/transfers')
            ->has('uploadLinks', 1)
            ->where('uploadLinks.0.title', 'Jaarstukken 2025')
            ->where('uploadLinks.0.url', route('upload-links.show', $link->token))
            ->where('uploadLinks.0.state', 'usable')
            ->has('uploadLinks.0.submissions', 1)
            ->where('uploadLinks.0.submissions.0.name', 'Anna de Vries')
            ->has('uploadLinks.0.submissions.0.files', 1)
        );
});

it('shows a member only their own links', function () {
    [, $workspace] = openUploadLink();
    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);

    actingAs($colleague)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('uploadLinks', 0));
});

it('clears a finished link and every byte that came in through it', function () {
    [$link] = openUploadLink([
        'expires_at' => now()->subDays(PruneTransfers::GRACE_DAYS + 1),
    ]);
    [$submission, $media] = receivedSubmission($link);
    $path = $media->getPathRelativeToRoot();

    artisan('transfers:prune')
        ->expectsOutputToContain('1 uploadlink opgeruimd.')
        ->assertSuccessful();

    expect(UploadLink::find($link->id))->toBeNull()
        ->and(UploadLinkSubmission::find($submission->id))->toBeNull();

    Storage::disk('local')->assertMissing($path);
});

it('leaves a link that only just closed', function () {
    [$link] = openUploadLink(['expires_at' => now()->subDay()]);

    artisan('transfers:prune')->assertSuccessful();

    expect(UploadLink::find($link->id))->not->toBeNull();
});

it('stops answering the moment the workspace switches transfers off', function () {
    [$link, $workspace] = openUploadLink();

    Feature::for($workspace)->deactivate(Transfers::class);

    $this->get(route('upload-links.show', $link->token))->assertNotFound();
});
