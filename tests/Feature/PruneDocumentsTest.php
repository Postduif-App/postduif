<?php

use App\Actions\Documents\PruneDocuments;
use App\Models\Document;
use App\Models\DocumentFile;
use Illuminate\Support\Facades\Storage;

test('een document dat lang genoeg weg is gaat er definitief uit', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $document->delete();
    $document->forceFill(['deleted_at' => now()->subDays(PruneDocuments::GRACE_DAYS + 1)])->saveQuietly();

    expect(app(PruneDocuments::class)->handle())->toBe(1)
        ->and(Document::withTrashed()->count())->toBe(0);
});

test('een net verwijderd document blijft in de prullenbak staan', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $document->delete();

    /*
     * De hele reden dat verwijderen zacht is: een verkeerde klik moet terug te
     * draaien zijn. Een prune die daar niet op wacht neemt dat weg.
     */
    expect(app(PruneDocuments::class)->handle())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);
});

test('een document dat gewoon bestaat wordt met rust gelaten', function () {
    [, , , $channel] = documentFixture();
    Document::factory()->create(['channel_id' => $channel->id]);

    expect(app(PruneDocuments::class)->handle())->toBe(0)
        ->and(Document::query()->count())->toBe(1);
});

test('de bestanden gaan mee, en van de schijf af', function () {
    Storage::fake('local');
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    $document->delete();
    $document->forceFill(['deleted_at' => now()->subDays(PruneDocuments::GRACE_DAYS + 1)])->saveQuietly();

    app(PruneDocuments::class)->handle();

    expect(DocumentFile::query()->count())->toBe(0);

    /*
     * Dit is waar de actie om draait. De foreign key op document_files
     * cascadeert, dus de rijen zouden hoe dan ook verdwijnen — maar een cascade
     * gebeurt binnen PostgreSQL, waar Eloquent niets van hoort, en dan draait
     * de deleted()-hook die de bytes weghaalt nooit. De rijen weg en de
     * bestanden voor eeuwig op de schijf is precies wat dit moest voorkomen.
     */
    Storage::disk('local')->assertMissing($file->path);
});

test('het commando zegt wat het heeft opgeruimd', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $document->delete();
    $document->forceFill(['deleted_at' => now()->subDays(PruneDocuments::GRACE_DAYS + 1)])->saveQuietly();

    $this->artisan('documents:prune')
        ->expectsOutputToContain(trans_choice('console.documents_pruned', 1))
        ->assertSuccessful();
});

test('het commando meldt het ook als er niets te doen was', function () {
    $this->artisan('documents:prune')
        ->expectsOutputToContain(__('console.nothing_to_prune'))
        ->assertSuccessful();
});
