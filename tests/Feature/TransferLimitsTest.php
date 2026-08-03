<?php

use App\Models\Transfer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * What keeps a link from being a permanent open door.
 *
 * The three reasons are tested apart rather than through isUsable() alone,
 * because the landing page tells them apart and so must this.
 */
it('hands the files over while nothing stands in the way', function () {
    expect(Transfer::factory()->create()->isUsable())->toBeTrue();
});

it('stops once the date has passed', function () {
    $transfer = Transfer::factory()->expired()->create();

    expect($transfer)
        ->hasExpired()->toBeTrue()
        ->isUsable()->toBeFalse()
        ->isRevoked()->toBeFalse()
        ->isExhausted()->toBeFalse();
});

it('stops once it has been fetched as often as it was allowed', function () {
    $transfer = Transfer::factory()->exhausted()->create();

    expect($transfer)
        ->isExhausted()->toBeTrue()
        ->isUsable()->toBeFalse()
        ->hasExpired()->toBeFalse();
});

it('stops once it is withdrawn, whatever else is still true of it', function () {
    $transfer = Transfer::factory()->revoked()->create(['expires_at' => now()->addYear()]);

    expect($transfer)
        ->isRevoked()->toBeTrue()
        ->isUsable()->toBeFalse();
});

it('treats no ceiling as no ceiling, however often it is fetched', function () {
    $transfer = Transfer::factory()->create(['max_downloads' => null, 'downloads' => 900]);

    expect($transfer)
        ->isExhausted()->toBeFalse()
        ->isUsable()->toBeTrue();
});

it('is exhausted the moment the last allowed fetch has happened', function () {
    $transfer = Transfer::factory()->once()->create(['downloads' => 1]);

    expect($transfer->isExhausted())->toBeTrue();
});

/**
 * The scope has to agree with the methods, including about the nulls: in SQL a
 * null compares to nothing, so "no ceiling" only reads as "not reached" if the
 * query says so.
 */
it('finds in SQL exactly what isUsable finds in PHP', function () {
    $usable = Transfer::factory()->create();
    $unlimited = Transfer::factory()->create(['max_downloads' => null, 'downloads' => 50]);

    Transfer::factory()->expired()->create();
    Transfer::factory()->revoked()->create();
    Transfer::factory()->exhausted()->create();

    expect(Transfer::usable()->pluck('id')->all())
        ->toEqualCanonicalizing([$usable->id, $unlimited->id]);
});

it('gives every transfer a token of its own that no payload carries along', function () {
    $first = Transfer::factory()->create();
    $second = Transfer::factory()->create();

    expect($first->token)
        ->not->toBe($second->token)
        ->toHaveLength(64);

    expect($first->toArray())->not->toHaveKey('token');
});

/**
 * The weight is read off the bytes on disk, which is why these two are made
 * with content rather than with the fake's create($name, $kilobytes) — that one
 * writes an empty file and only reports a size to the validator.
 */
it('holds more than one file and knows what the lot weighs', function () {
    Storage::fake('local');

    $transfer = Transfer::factory()->create();

    $transfer->addMedia(UploadedFile::fake()->createWithContent('begroting.txt', str_repeat('a', 1200)))
        ->toMediaCollection(Transfer::FILES);
    $transfer->addMedia(UploadedFile::fake()->createWithContent('bijlage.txt', str_repeat('b', 800)))
        ->toMediaCollection(Transfer::FILES);

    expect($transfer->refresh()->files())->toHaveCount(2)
        ->and($transfer->size())->toBe(2000);
});

/**
 * A message keeps its files after deletion so the thread stays readable. A
 * transfer is the other case: withdrawn is withdrawn, and the bytes go too.
 */
it('takes its files off the disk when it is deleted', function () {
    Storage::fake('local');

    $transfer = Transfer::factory()->create();
    $media = $transfer->addMedia(UploadedFile::fake()->create('offerte.pdf', 20))
        ->toMediaCollection(Transfer::FILES);

    $path = $media->getPathRelativeToRoot();
    Storage::disk('local')->assertExists($path);

    $transfer->delete();

    Storage::disk('local')->assertMissing($path);
});

it('goes when its workspace goes', function () {
    $workspace = Workspace::factory()->create();
    Transfer::factory()->create(['workspace_id' => $workspace->id]);

    $workspace->delete();

    expect(Transfer::count())->toBe(0);
});

/**
 * The other way round for the sender: a colleague's last day should not break
 * a link somebody outside is still waiting on.
 */
it('outlives the person who sent it', function () {
    $sender = User::factory()->create();
    $transfer = Transfer::factory()->create(['created_by' => $sender->id]);

    $sender->delete();

    expect($transfer->refresh())
        ->created_by->toBeNull()
        ->isUsable()->toBeTrue();
});
