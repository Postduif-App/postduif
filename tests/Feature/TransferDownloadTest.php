<?php

use App\Features\Transfers;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('shows somebody without an account what is waiting for them', function () {
    [$transfer] = waitingTransfer(files: 2);

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('transfers/show')
            ->where('transfer.state', 'usable')
            ->where('transfer.title', 'Offerte week 32')
            ->has('transfer.files', 2)
            ->where('transfer.downloadsLeft', null)
        );
});

it('says nothing at all about a token nobody recognises', function () {
    waitingTransfer();

    get(route('transfers.show', str_repeat('x', 64)))->assertNotFound();
});

/**
 * The three reasons are kept apart on the model so this page can name them, and
 * naming them is the point: what the holder should do next differs per reason.
 */
it('says which of the three reasons it has stopped working', function (array $state, string $expected) {
    [$transfer] = waitingTransfer($state);

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.state', $expected));
})->with([
    'expired' => [['expires_at' => now()->subDay()], 'expired'],
    'withdrawn' => [['revoked_at' => now()->subHour()], 'revoked'],
    'used up' => [['max_downloads' => 2, 'downloads' => 2], 'exhausted'],
]);

/** The file names alone can be the sensitive part of a withdrawn transfer. */
it('stops naming the files once the link has stopped working', function () {
    [$transfer] = waitingTransfer(['revoked_at' => now()], files: 3);

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transfer.files', 0)
            ->where('transfer.downloadAllUrl', null)
        );
});

it('tells the recipient how many fetches are left', function () {
    [$transfer] = waitingTransfer(['max_downloads' => 3, 'downloads' => 1]);

    get(route('transfers.show', $transfer->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfer.downloadsLeft', 2));
});

it('hands over a file and counts the fetch', function () {
    [$transfer] = waitingTransfer();
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($transfer->refresh()->downloads)->toBe(1);
});

/**
 * Never inline, whatever the file is. The route sits on our own origin, so a
 * page served in place would run its script as us — and it is this guarantee
 * that lets the sending side accept file types a message never would.
 */
it('never renders a file in place, however harmless it looks', function (string $name, string $content) {
    [$transfer] = waitingTransfer(files: 0);

    $media = $transfer->addMedia(UploadedFile::fake()->createWithContent($name, $content))
        ->toMediaCollection(Transfer::FILES);

    $response = get(route('transfers.download', [$transfer->token, $media->id]))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
})->with([
    'html' => ['pagina.html', '<script>alert(1)</script>'],
    'svg' => ['plaatje.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
]);

it('hands over the lot as one archive, counted once', function () {
    [$transfer] = waitingTransfer(files: 3);

    get(route('transfers.download-all', $transfer->token))->assertOk();

    expect($transfer->refresh()->downloads)->toBe(1);
});

it('refuses to hand anything over once the link has stopped working', function (array $state) {
    [$transfer] = waitingTransfer($state);
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertGone();
    get(route('transfers.download-all', $transfer->token))->assertGone();
})->with([
    'expired' => [['expires_at' => now()->subDay()]],
    'withdrawn' => [['revoked_at' => now()->subHour()]],
    'used up' => [['max_downloads' => 1, 'downloads' => 1]],
]);

/** The ceiling has to bite on the fetch that reaches it, not the one after. */
it('stops handing over the moment the allowance runs out', function () {
    [$transfer] = waitingTransfer(['max_downloads' => 2]);
    $media = $transfer->files()->first();

    get(route('transfers.download', [$transfer->token, $media->id]))->assertOk();
    get(route('transfers.download', [$transfer->token, $media->id]))->assertOk();
    get(route('transfers.download', [$transfer->token, $media->id]))->assertGone();

    expect($transfer->refresh()->downloads)->toBe(2);
});

/**
 * The media table is shared by everything here that keeps files, so an id from
 * a message would resolve perfectly well without this check.
 */
it('does not hand over a file from another transfer', function () {
    [$transfer] = waitingTransfer();
    [$other] = waitingTransfer();

    $stranger = $other->files()->first();

    get(route('transfers.download', [$transfer->token, $stranger->id]))->assertNotFound();

    expect($transfer->refresh()->downloads)->toBe(0);
});

/**
 * A beheerder who switches the feature off expects the links to stop, not to
 * keep working until each one expires on its own.
 */
it('stops working when the workspace switches sending off', function () {
    [$transfer, $workspace] = waitingTransfer();
    $media = $transfer->files()->first();

    Feature::for($workspace)->deactivate(Transfers::class);

    get(route('transfers.show', $transfer->token))->assertNotFound();
    get(route('transfers.download', [$transfer->token, $media->id]))->assertNotFound();
});

/** Being signed in is neither required nor an advantage — the token is the credential. */
it('works the same for a member who happens to be signed in', function () {
    [$transfer] = waitingTransfer();

    actingAs(User::factory()->create())
        ->get(route('transfers.show', $transfer->token))
        ->assertOk();
});
