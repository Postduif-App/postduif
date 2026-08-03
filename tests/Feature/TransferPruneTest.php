<?php

use App\Actions\Transfers\PruneTransfers;
use App\Models\Transfer;
use App\Models\TransferDownload;
use App\Models\TransferRecipient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/** A transfer with a real file on the fake disk, so the bytes can be checked. */
function prunableTransfer(array $state = []): array
{
    Storage::fake('local');

    $transfer = Transfer::factory()->create($state);
    $media = $transfer->addMedia(UploadedFile::fake()->createWithContent('offerte.pdf', 'inhoud'))
        ->toMediaCollection(Transfer::FILES);

    return [$transfer->refresh(), $media->getPathRelativeToRoot()];
}

it('leaves a transfer that is still doing its job', function () {
    [$transfer, $path] = prunableTransfer();

    artisan('transfers:prune')->assertSuccessful();

    expect(Transfer::find($transfer->id))->not->toBeNull();
    Storage::disk('local')->assertExists($path);
});

/**
 * The grace period is the ordinary Monday morning: a link expires over the
 * weekend and the sender wants to move the date rather than upload it all again.
 */
it('leaves a transfer that only just expired', function () {
    [$transfer] = prunableTransfer(['expires_at' => now()->subDays(2)]);

    artisan('transfers:prune')->assertSuccessful();

    expect(Transfer::find($transfer->id))->not->toBeNull();
});

it('takes the files off the disk once the grace period has passed', function () {
    [$transfer, $path] = prunableTransfer([
        'expires_at' => now()->subDays(PruneTransfers::GRACE_DAYS + 1),
    ]);

    artisan('transfers:prune')
        ->expectsOutputToContain('1 verzending opgeruimd.')
        ->assertSuccessful();

    expect(Transfer::find($transfer->id))->toBeNull();

    // The row going without the bytes going is the failure this command exists
    // to prevent — a mass delete would do exactly that, because the media
    // library removes files on the model's own delete event.
    Storage::disk('local')->assertMissing($path);
});

it('clears a withdrawn transfer once it has been withdrawn long enough', function () {
    [$transfer, $path] = prunableTransfer([
        'revoked_at' => now()->subDays(PruneTransfers::GRACE_DAYS + 1),
        'expires_at' => now()->addYear(),
    ]);

    artisan('transfers:prune')->assertSuccessful();

    expect(Transfer::find($transfer->id))->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

/**
 * Being used up is not being finished: the sender may well raise the ceiling,
 * and the link has weeks left to run.
 */
it('keeps a transfer that has only run out of downloads', function () {
    [$transfer] = prunableTransfer([
        'max_downloads' => 1,
        'downloads' => 1,
        'expires_at' => now()->addWeek(),
    ]);

    artisan('transfers:prune')->assertSuccessful();

    expect(Transfer::find($transfer->id))->not->toBeNull();
});

/**
 * The log holds IP addresses of people who never had an account here. They must
 * not outlive the transfer they are about, which is the whole reason the row is
 * deleted rather than emptied.
 */
it('takes the recipients and the download log with it', function () {
    [$transfer] = prunableTransfer([
        'expires_at' => now()->subDays(PruneTransfers::GRACE_DAYS + 1),
    ]);

    $recipient = TransferRecipient::factory()->create(['transfer_id' => $transfer->id]);
    TransferDownload::factory()->count(3)->create([
        'transfer_id' => $transfer->id,
        'transfer_recipient_id' => $recipient->id,
    ]);

    artisan('transfers:prune')->assertSuccessful();

    expect(TransferRecipient::count())->toBe(0)
        ->and(TransferDownload::count())->toBe(0);
});

it('says so plainly when there is nothing to do', function () {
    artisan('transfers:prune')
        ->expectsOutputToContain('Niets om op te ruimen.')
        ->assertSuccessful();
});

it('clears several at once and counts them', function () {
    Storage::fake('local');

    Transfer::factory()->count(3)->create([
        'expires_at' => now()->subDays(PruneTransfers::GRACE_DAYS + 1),
    ]);
    Transfer::factory()->create();

    artisan('transfers:prune')
        ->expectsOutputToContain('3 verzendingen opgeruimd.')
        ->assertSuccessful();

    expect(Transfer::count())->toBe(1);
});

/** The clock is what decides, so moving it has to change the answer. */
it('clears a transfer the moment it has been finished long enough', function () {
    [$transfer] = prunableTransfer(['expires_at' => now()->subDay()]);

    artisan('transfers:prune')->assertSuccessful();
    expect(Transfer::find($transfer->id))->not->toBeNull();

    $this->travelTo(now()->addDays(PruneTransfers::GRACE_DAYS + 1));

    artisan('transfers:prune')->assertSuccessful();
    expect(Transfer::find($transfer->id))->toBeNull();
});
