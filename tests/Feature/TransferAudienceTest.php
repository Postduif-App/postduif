<?php

use App\Enums\TransferAudience;
use App\Enums\WorkspaceRole;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * A members-only transfer, and the workspace it belongs to.
 *
 * @return array{0: Transfer, 1: Workspace, 2: User}
 */
function membersOnlyTransfer(): array
{
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->membersOnly()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    $transfer->addMedia(UploadedFile::fake()->createWithContent('binnen.txt', 'geheim'))
        ->toMediaCollection(Transfer::FILES);

    return [$transfer->refresh(), $workspace, $sender];
}

it('lets the sender choose who the link works for', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::WorkspaceMembers->value,
        ])
        ->assertRedirect();

    expect(Transfer::sole()->audience)->toBe(TransferAudience::WorkspaceMembers);
});

/**
 * Asked rather than defaulted at this layer: quietly picking the widest option
 * for somebody is the wrong way to answer "who may use this".
 */
it('insists the sender says who it is for', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
        ])
        ->assertSessionHasErrors('audience');
});

it('refuses a public nobody offered', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => 'de-hele-wereld',
        ])
        ->assertSessionHasErrors('audience');
});

/**
 * A colleague following the link from their mail has done nothing wrong, so the
 * login screen is the answer to their situation rather than an error page.
 */
it('sends a signed-out visitor to log in rather than turning them away', function () {
    [$transfer] = membersOnlyTransfer();

    get(route('transfers.show', $transfer->token))->assertRedirect(route('login'));
});

it('opens for a member of the workspace it came from', function () {
    [$transfer, $workspace] = membersOnlyTransfer();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($colleague)
        ->get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfer.files', 1));
});

/**
 * The whole point of the setting: a link forwarded outside is worth nothing.
 * A 404 rather than a 403, because "not you" would confirm the files are there.
 */
it('is nothing at all to somebody signed in from outside the workspace', function () {
    [$transfer] = membersOnlyTransfer();
    $media = $transfer->files()->first();

    actingAs(User::factory()->create())
        ->get(route('transfers.show', $transfer->token))
        ->assertNotFound();

    actingAs(User::factory()->create())
        ->get(route('transfers.download', [$transfer->token, $media->id]))
        ->assertNotFound();
});

/**
 * No redirect on the download routes: the browser follows these expecting a
 * file, and a login page where a file was expected is a broken download.
 */
it('refuses a download outright rather than redirecting the browser', function () {
    [$transfer] = membersOnlyTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertNotFound();
    get(route('transfers.download-all', $transfer->token))->assertNotFound();

    expect($transfer->refresh()->downloads)->toBe(0);
});

/** The open kind must keep working for somebody with no account at all. */
it('leaves an everyone link open to everyone', function () {
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);
    $transfer->addMedia(UploadedFile::fake()->createWithContent('vrij.txt', 'hallo'))
        ->toMediaCollection(Transfer::FILES);

    get(route('transfers.show', $transfer->token))->assertOk();
    get(route('transfers.download', [$transfer->token, $transfer->refresh()->files()->first()->id]))
        ->assertOk();
});

it('shows the sender which links are narrowed and which are not', function () {
    [$user, $workspace] = senderInWorkspace();

    Transfer::factory()->membersOnly()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    actingAs($user)
        ->get(route('chat.transfers.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfers.0.audience', TransferAudience::WorkspaceMembers->value)
            ->has('audienceOptions', count(TransferAudience::cases()))
        );
});
