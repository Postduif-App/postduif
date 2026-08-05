<?php

use App\Enums\SystemRole;
use App\Enums\TransferAudience;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * The smallest request the endpoint takes.
 *
 * The audience is spelled out rather than left to a default: the endpoint
 * insists on it, so that nobody gets the widest setting by not answering. See
 * TransferAudienceTest.
 *
 * @return array<string, mixed>
 */
function transferPayload(array $overrides = []): array
{
    return [
        'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
        'valid_for_days' => 7,
        'audience' => TransferAudience::Everyone->value,
        ...$overrides,
    ];
}

it('puts files aside behind a link of their own', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload([
            'files' => [
                UploadedFile::fake()->create('offerte.pdf', 40),
                UploadedFile::fake()->create('bijlage.zip', 60),
            ],
            'title' => 'Offerte week 32',
            'message' => 'Laat maar weten wat je ervan vindt.',
            'max_downloads' => 3,
        ]))
        ->assertRedirect();

    $transfer = Transfer::sole();

    expect($transfer)
        ->workspace_id->toBe($workspace->id)
        ->created_by->toBe($user->id)
        ->title->toBe('Offerte week 32')
        ->max_downloads->toBe(3)
        ->downloads->toBe(0)
        ->isUsable()->toBeTrue();

    expect($transfer->files())->toHaveCount(2)
        ->and($transfer->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();
});

it('refuses to make a transfer with nothing in it', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'valid_for_days' => 7,
            'audience' => TransferAudience::Everyone->value,
        ])
        ->assertSessionHasErrors('files');

    expect(Transfer::count())->toBe(0);
});

/**
 * The one limit with no "unbounded" option: expiry is what hands the storage
 * back, so a transfer without it would be a permanent one.
 */
it('insists on a date the link stops working', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'audience' => TransferAudience::Everyone->value,
        ])
        ->assertSessionHasErrors('valid_for_days');
});

it('will not let a link outlive what the workspace allows', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['max_transfer_days' => 5]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload(['valid_for_days' => 30]))
        ->assertSessionHasErrors('valid_for_days');
});

it('refuses a single file over the workspace ceiling', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['max_transfer_kb' => 100]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload([
            'files' => [UploadedFile::fake()->create('groot.zip', 500)],
        ]))
        ->assertSessionHasErrors('files.0');
});

/**
 * The ceiling is on the lot. Each of these three passes on its own, which is
 * exactly the case a per-file rule would wave through.
 */
it('refuses files that only break the ceiling together', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['max_transfer_kb' => 100]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload([
            'files' => [
                UploadedFile::fake()->createWithContent('een.txt', str_repeat('a', 40 * 1024)),
                UploadedFile::fake()->createWithContent('twee.txt', str_repeat('b', 40 * 1024)),
                UploadedFile::fake()->createWithContent('drie.txt', str_repeat('c', 40 * 1024)),
            ],
        ]))
        ->assertSessionHasErrors('files');

    expect(Transfer::count())->toBe(0);
});

/**
 * Not an oversight: a transfer is for the file that does not belong in a
 * conversation. What keeps that safe is the download route, which hands
 * everything over as an attachment rather than rendering it.
 */
it('takes a file type a message would never accept', function () {
    [$user, $workspace] = senderInWorkspace();
    $workspace->update(['uploads_enabled' => false]);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload([
            'files' => [UploadedFile::fake()->create('installer.dmg', 40)],
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Transfer::count())->toBe(1);
});

it('does not exist at all in a workspace that never switched it on', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload())
        ->assertNotFound();

    expect(Transfer::count())->toBe(0);
});

/** A guest is somebody from outside; making links on our behalf is not theirs. */
it('does not let a guest send files out of the workspace', function () {
    [$user, $workspace] = senderInWorkspace(SystemRole::Guest);

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), transferPayload())
        ->assertForbidden();
});

it('does not let somebody outside the workspace send from it', function () {
    [, $workspace] = senderInWorkspace();
    $outsider = User::factory()->create();

    actingAs($outsider)
        ->post(route('chat.transfers.store', $workspace), transferPayload())
        ->assertForbidden();
});

it('withdraws a transfer without losing the record of it', function () {
    [$user, $workspace] = senderInWorkspace();
    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'downloads' => 4,
    ]);

    actingAs($user)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertRedirect();

    expect($transfer->refresh())
        ->isRevoked()->toBeTrue()
        ->isUsable()->toBeFalse()
        ->downloads->toBe(4);
});

/** The moment it stopped working is the interesting one; a second click must not move it. */
it('leaves the moment of withdrawal where it was', function () {
    [$user, $workspace] = senderInWorkspace();
    $transfer = Transfer::factory()->revoked()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);

    $stopped = $transfer->revoked_at;

    actingAs($user)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertRedirect();

    expect($transfer->refresh()->revoked_at->equalTo($stopped))->toBeTrue();
});

it('does not let a colleague withdraw what somebody else sent', function () {
    [$sender, $workspace] = senderInWorkspace();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    actingAs($colleague)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertForbidden();

    expect($transfer->refresh()->isRevoked())->toBeFalse();
});

/**
 * Not to police what colleagues send, but so a file sent to the wrong address
 * can be stopped by somebody who is still around.
 */
it('lets whoever runs the workspace stop a transfer', function () {
    [$sender, $workspace] = senderInWorkspace();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    actingAs($admin)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertRedirect();

    expect($transfer->refresh()->isRevoked())->toBeTrue();
});

/** The id is in the URL, so a transfer from elsewhere must not answer to it. */
it('does not reach a transfer through another workspace', function () {
    [$user, $workspace] = senderInWorkspace();

    $elsewhere = Workspace::factory()->create();
    $transfer = Transfer::factory()->create(['workspace_id' => $elsewhere->id]);

    actingAs($user)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertNotFound();
});
