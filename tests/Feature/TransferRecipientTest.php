<?php

use App\Enums\TransferAudience;
use App\Mail\TransferReadyMail;
use App\Models\Transfer;
use App\Models\TransferRecipient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * A transfer addressed to two people, each with a link of their own.
 *
 * @return array{0: Transfer, 1: TransferRecipient, 2: TransferRecipient, 3: Workspace, 4: User}
 */
function addressedTransfer(): array
{
    [$sender, $workspace] = senderInWorkspace();

    $transfer = Transfer::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $sender->id,
        'audience' => TransferAudience::NamedRecipients,
    ]);

    $transfer->addMedia(UploadedFile::fake()->createWithContent('offerte.pdf', 'inhoud'))
        ->toMediaCollection(Transfer::FILES);

    $first = TransferRecipient::factory()->create([
        'transfer_id' => $transfer->id,
        'email' => 'anna@klant.nl',
    ]);
    $second = TransferRecipient::factory()->create([
        'transfer_id' => $transfer->id,
        'email' => 'bram@klant.nl',
    ]);

    return [$transfer->refresh(), $first, $second, $workspace, $sender];
}

it('gives every named address a link of its own and mails it', function () {
    Mail::fake();

    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::NamedRecipients->value,
            'recipients' => ['anna@klant.nl', 'bram@klant.nl'],
        ])
        ->assertRedirect();

    $recipients = Transfer::sole()->recipients;

    expect($recipients)->toHaveCount(2)
        ->and($recipients->pluck('email')->all())
        ->toEqualCanonicalizing(['anna@klant.nl', 'bram@klant.nl'])
        // Two addresses, two secrets: one shared token would make the whole
        // arrangement decorative.
        ->and($recipients->pluck('token')->unique())->toHaveCount(2);

    Mail::assertSent(TransferReadyMail::class, 2);
});

it('refuses to address a transfer to nobody', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::NamedRecipients->value,
        ])
        ->assertSessionHasErrors('recipients');

    expect(Transfer::count())->toBe(0);
});

it('refuses an address that is not one', function () {
    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::NamedRecipients->value,
            'recipients' => ['anna@klant.nl', 'geen adres'],
        ])
        ->assertSessionHasErrors('recipients.1');
});

/** A list of addresses on an open link would suggest a restriction nobody applies. */
it('ignores addresses on a transfer that is open to everyone', function () {
    Mail::fake();

    [$user, $workspace] = senderInWorkspace();

    actingAs($user)
        ->post(route('chat.transfers.store', $workspace), [
            'files' => [UploadedFile::fake()->create('offerte.pdf', 40)],
            'valid_for_days' => 7,
            'audience' => TransferAudience::Everyone->value,
            'recipients' => ['anna@klant.nl'],
        ])
        ->assertRedirect();

    expect(Transfer::sole()->recipients)->toHaveCount(0);
    Mail::assertNothingSent();
});

it('opens for the person it was addressed to', function () {
    [, $first] = addressedTransfer();

    get(route('transfers.show', $first->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('transfer.files', 1));
});

/**
 * The point of the whole audience. If the shared token still worked beside the
 * personal ones, the list of addresses would be a suggestion.
 */
it('opens nothing with the transfer own token', function () {
    [$transfer] = addressedTransfer();

    get(route('transfers.show', $transfer->token))->assertNotFound();
    get(route('transfers.download-all', $transfer->token))->assertNotFound();
});

it('does not open another transfer with a recipient token', function () {
    [, $first] = addressedTransfer();
    [$other] = addressedTransfer();

    $stranger = $other->files()->first();

    get(route('transfers.download', [$first->token, $stranger->id]))->assertNotFound();
});

it('counts a fetch against the address it was given to', function () {
    [$transfer, $first, $second] = addressedTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$first->token, $media->id]))->assertOk();

    expect($first->refresh())
        ->downloads->toBe(1)
        ->last_downloaded_at->not->toBeNull();

    // The other address is untouched, which is what makes the tally worth
    // reading: a forwarded link shows up as one recipient counting twice.
    expect($second->refresh()->downloads)->toBe(0);

    // And the shared counter still sees every fetch, whoever made it.
    expect($transfer->refresh()->downloads)->toBe(1);
});

it('withdraws one address without disturbing the others', function () {
    [$transfer, $first, $second, $workspace, $sender] = addressedTransfer();

    actingAs($sender)
        ->delete(route('chat.transfers.recipients.destroy', [$workspace, $transfer, $first]))
        ->assertRedirect();

    get(route('transfers.show', $first->token))->assertNotFound();
    get(route('transfers.show', $second->token))->assertOk();
});

it('does not let a stranger withdraw an address', function () {
    [$transfer, $first, , $workspace] = addressedTransfer();

    actingAs(User::factory()->create())
        ->delete(route('chat.transfers.recipients.destroy', [$workspace, $transfer, $first]))
        ->assertForbidden();

    expect($first->refresh()->isRevoked())->toBeFalse();
});

/** The id is in the URL, so a recipient of another transfer must not answer to it. */
it('does not withdraw an address that belongs to another transfer', function () {
    [$transfer, , , $workspace, $sender] = addressedTransfer();
    [, $stranger] = addressedTransfer();

    actingAs($sender)
        ->delete(route('chat.transfers.recipients.destroy', [$workspace, $transfer, $stranger]))
        ->assertNotFound();
});

it('shows the sender who has fetched and who has not', function () {
    [$transfer, $first, , , $sender] = addressedTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$first->token, $media->id]))->assertOk();

    actingAs($sender)
        ->get(route('workspace.transfers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transfers.0.recipients', 2)
            ->where('transfers.0.recipients.0.email', 'anna@klant.nl')
            ->where('transfers.0.recipients.0.downloads', 1)
            ->where('transfers.0.recipients.1.downloads', 0)
            ->where('transfers.0.recipients.0.url', route('transfers.show', $first->token))
        );
});

/** The transfer's own limits still hold over every personal link. */
it('stops every personal link when the transfer itself is withdrawn', function () {
    [$transfer, $first, $second, $workspace, $sender] = addressedTransfer();

    actingAs($sender)
        ->delete(route('chat.transfers.destroy', [$workspace, $transfer]))
        ->assertRedirect();

    get(route('transfers.show', $first->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.state', 'revoked'));

    get(route('transfers.download-all', $second->token))->assertGone();
});
