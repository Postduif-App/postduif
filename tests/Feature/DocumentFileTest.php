<?php

use App\Actions\Documents\ReconcileDocumentFiles;
use App\Enums\ChannelDocumentPolicy;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A body that mentions the given files, in the shape the editor saves.
 *
 * The nesting is not decoration: ReconcileDocumentFiles walks the whole tree
 * rather than the top level, because how deep a fileId sits belongs to whichever
 * plugin made the block.
 *
 * @param  array<int, int>  $ids
 * @return array<string, mixed>
 */
function bodyMentioning(array $ids): array
{
    $blocks = [];

    foreach ($ids as $index => $id) {
        $blocks['blok-'.$index] = [
            'id' => 'blok-'.$index,
            'type' => 'Image',
            'value' => [
                ['id' => 'el-'.$index, 'type' => 'image', 'props' => ['fileId' => $id, 'src' => '/x']],
            ],
        ];
    }

    return $blocks;
}

test('een schrijver kan een afbeelding in een document zetten', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $response = $this->actingAs($member)
        ->postJson(route('chat.documents.files.store', [$workspace, $channel, $document]), [
            'file' => UploadedFile::fake()->image('schema.png', 640, 480),
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['id', 'url', 'name', 'mimeType', 'size', 'width', 'height']);

    $file = DocumentFile::query()->sole();

    expect($file->document_id)->toBe($document->id)
        ->and($file->uploaded_by)->toBe($member->id)
        ->and($file->name)->toBe('schema.png')
        // Read from the bytes rather than taken from the browser, so the editor
        // can hold the right amount of space before the picture arrives.
        ->and($file->width)->toBe(640)
        ->and($file->height)->toBe(480);

    Storage::disk('local')->assertExists($file->path);
});

test('een gast mag een document lezen maar er niets in zetten', function () {
    Storage::fake('local');

    /*
     * The Members policy, not the fixture's default. On Everyone a guest may
     * write — that is what the setting is for — and uploading is judged by the
     * same rule as typing, so the file follows the words.
     */
    [, $guest, $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Members);
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($guest)
        ->postJson(route('chat.documents.files.store', [$workspace, $channel, $document]), [
            'file' => UploadedFile::fake()->image('schema.png'),
        ])
        ->assertForbidden();

    expect(DocumentFile::query()->count())->toBe(0);
});

test('een gast ziet de afbeeldingen in een document wel', function () {
    Storage::fake('local');
    [, $guest, $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    $this->actingAs($guest)
        ->get(route('chat.documents.files.show', [$workspace, $channel, $document, $file]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        // Reading is the whole point of a picture in a page, so it is served in
        // place — but never without saying that the type is not up for guessing.
        ->assertHeader('x-content-type-options', 'nosniff');
});

test('iemand buiten het kanaal komt niet bij een bestand', function () {
    Storage::fake('local');
    [, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    $this->actingAs(User::factory()->create())
        ->get(route('chat.documents.files.show', [$workspace, $channel, $document, $file]))
        ->assertForbidden();
});

test('een bestand van een ander document is een 404 en geen download', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $other = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create(['document_id' => $other->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    $this->actingAs($member)
        ->get(route('chat.documents.files.show', [$workspace, $channel, $document, $file]))
        ->assertNotFound();
});

test('alles wat geen plaatje is gaat als download de deur uit', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create([
        'document_id' => $document->id,
        'name' => 'boekhouding.html',
        'mime_type' => 'text/html',
    ]);
    Storage::disk('local')->put($file->path, '<script>alert(1)</script>');

    /*
     * The line this guards: the route sits on our own origin, so an uploaded
     * .html served inline would run its script as us.
     */
    $this->actingAs($member)
        ->get(route('chat.documents.files.show', [$workspace, $channel, $document, $file]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="boekhouding.html"');
});

test('een schrijver kan een bestand meteen weghalen', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    $this->actingAs($member)
        ->deleteJson(route('chat.documents.files.destroy', [$workspace, $channel, $document, $file]))
        ->assertNoContent();

    expect(DocumentFile::query()->count())->toBe(0);

    // The row and the bytes go together: a row removed on its own would leave
    // the file behind forever.
    Storage::disk('local')->assertMissing($file->path);
});

test('een workspace zonder uploads neemt ook in een document niets aan', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $workspace->update(['uploads_enabled' => false]);
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->postJson(route('chat.documents.files.store', [$workspace, $channel, $document]), [
            'file' => UploadedFile::fake()->image('schema.png'),
        ])
        ->assertJsonValidationErrors('file');

    expect(DocumentFile::query()->count())->toBe(0);
});

test('opslaan ruimt op wat het document niet meer noemt', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $kept = DocumentFile::factory()->abandoned()->create(['document_id' => $document->id]);
    $dropped = DocumentFile::factory()->abandoned()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($kept->path, 'bytes');
    Storage::disk('local')->put($dropped->path, 'bytes');

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => bodyMentioning([$kept->id]),
            'body_text' => 'Een regel',
        ]);

    expect(DocumentFile::query()->pluck('id')->all())->toBe([$kept->id]);
    Storage::disk('local')->assertMissing($dropped->path);
    Storage::disk('local')->assertExists($kept->path);
});

test('een net geupload bestand overleeft de eerstvolgende opslag', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    /*
     * The gap this covers: a file is on the disk the moment it is dropped, and
     * the block naming it is only saved when autosave next fires. Without the
     * hour of grace, every upload would be deleted by the save that was meant
     * to record it.
     */
    $fresh = DocumentFile::factory()->create(['document_id' => $document->id]);

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['blok' => ['type' => 'Paragraph']],
            'body_text' => 'Nog niets over het plaatje',
        ]);

    expect($fresh->fresh())->not->toBeNull();
});

test('hernoemen laat de bestanden met rust', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->abandoned()->create(['document_id' => $document->id]);

    // A title says nothing about which files a document mentions, so a rename
    // must not be a delete for everything the body has not been re-sent for.
    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'title' => 'Een andere naam',
        ]);

    expect($file->fresh())->not->toBeNull();
});

test('een document dat weggegooid wordt neemt zijn bestanden mee', function () {
    Storage::fake('local');
    [$member, , , $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'created_by' => $member->id,
    ]);
    DocumentFile::factory()->create(['document_id' => $document->id]);

    $document->forceDelete();

    expect(DocumentFile::query()->count())->toBe(0);
});

test('een bestand dat alleen als adres in het document staat blijft staan', function () {
    Storage::fake('local');
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->abandoned()->create(['document_id' => $document->id]);
    Storage::disk('local')->put($file->path, 'bytes');

    /*
     * The half of the reconciliation that does not trust the props. Which keys
     * a plugin holds on to is the plugin's business; a picture that is visibly
     * on the page has its address on the page, and deleting that file is the
     * one mistake here nobody can undo.
     */
    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['blok' => [
                'type' => 'Image',
                'value' => [['props' => ['src' => $file->url()]]],
            ]],
            'body_text' => '',
        ]);

    expect($file->fresh())->not->toBeNull();
});

test('de reconciliatie laat een bestand van een ander document staan', function () {
    [, , , $channel] = documentFixture();
    $mine = Document::factory()->create(['channel_id' => $channel->id]);
    $theirs = Document::factory()->create(['channel_id' => $channel->id]);
    $file = DocumentFile::factory()->abandoned()->create(['document_id' => $theirs->id]);

    app(ReconcileDocumentFiles::class)->handle($mine);

    expect($file->fresh())->not->toBeNull();
});
