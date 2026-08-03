<?php

use App\Enums\TransferAudience;
use App\Models\Transfer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * A transfer with something the recipient has to know as well as hold.
 *
 * @return array{0: Transfer, 1: string}
 */
function lockedTransfer(string $password = 'geheim123'): array
{
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->locked($password)->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    $transfer->addMedia(UploadedFile::fake()->createWithContent('offerte.pdf', 'inhoud'))
        ->toMediaCollection(Transfer::FILES);

    return [$transfer->refresh(), $password];
}

it('hashes the password and never hands it back', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::Everyone->value,
            'password' => 'geheim123',
        ])
        ->assertRedirect();

    $transfer = Transfer::sole();

    expect($transfer->password)->not->toBe('geheim123')
        ->and(Hash::check('geheim123', $transfer->password))->toBeTrue()
        ->and($transfer->toArray())->not->toHaveKey('password');
});

it('refuses a password too short to be worth having', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::Everyone->value,
            'password' => 'kort',
        ])
        ->assertSessionHasErrors('password');
});

/** Showing file names above a password box gives away the part being protected. */
it('says nothing about the contents while the lock is shut', function () {
    [$transfer] = lockedTransfer();

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfer.isLocked', true)
            // Alive, not dead — the visitor is one step short rather than out
            // of luck, and the state has to keep saying so.
            ->where('transfer.state', 'usable')
            ->has('transfer.files', 0)
            ->where('transfer.downloadAllUrl', null)
        );
});

it('shows what is waiting once the password is answered', function () {
    [$transfer, $password] = lockedTransfer();

    post(route('transfers.unlock', $transfer->token), ['password' => $password])
        ->assertRedirect();

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfer.isLocked', false)
            ->has('transfer.files', 1)
        );
});

it('does not open on a wrong password', function () {
    [$transfer] = lockedTransfer();

    post(route('transfers.unlock', $transfer->token), ['password' => 'gokje1234'])
        ->assertSessionHasErrors('password');

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.isLocked', true));
});

/**
 * A 403 rather than the 404 the audience checks use: the visitor is looking at
 * the page, so pretending the transfer is not there would only confuse. What
 * they are missing is the password, and that is what the status says.
 */
it('hands over no file until the password is answered', function () {
    [$transfer, $password] = lockedTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertForbidden();
    get(route('transfers.download-all', $transfer->token))->assertForbidden();

    expect($transfer->refresh()->downloads)->toBe(0);

    post(route('transfers.unlock', $transfer->token), ['password' => $password]);

    get(route('transfers.download', [$transfer->token, $media->id]))->assertOk();
});

/**
 * The whole reason the session flag is keyed by transfer: one shared "unlocked"
 * would turn one password into a key for every transfer the browser visits.
 */
it('does not let the password to one transfer open another', function () {
    [$first, $password] = lockedTransfer();
    [$second] = lockedTransfer('anderwoord9');

    post(route('transfers.unlock', $first->token), ['password' => $password]);

    get(route('transfers.show', $second->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.isLocked', true));

    get(route('transfers.download-all', $second->token))->assertForbidden();
});

/**
 * Without this, posting to an unlocked transfer would write the session flag —
 * harmless today, and exactly what stops being harmless when something later
 * reads that flag.
 */
it('accepts nothing rather than everything on a transfer without a password', function () {
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
    ]);

    post(route('transfers.unlock', $transfer->token), ['password' => 'wat dan ook'])
        ->assertSessionHasErrors('password');
});

it('still refuses once the transfer itself has stopped working', function () {
    [$transfer, $password] = lockedTransfer();

    post(route('transfers.unlock', $transfer->token), ['password' => $password]);

    $transfer->forceFill(['revoked_at' => now()])->save();

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transfer.state', 'revoked')
            ->has('transfer.files', 0)
        );

    get(route('transfers.download-all', $transfer->token))->assertGone();
});
