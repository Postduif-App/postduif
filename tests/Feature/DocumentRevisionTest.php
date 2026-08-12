<?php

use App\Actions\Documents\PruneDocuments;
use App\Actions\Documents\RecordDocumentRevision;
use App\Enums\ChannelDocumentPolicy;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;

/** A body with one paragraph saying the given words. */
function bodySaying(string $text): array
{
    return ['blok' => [
        'id' => 'blok',
        'type' => 'Paragraph',
        'meta' => ['order' => 0, 'depth' => 0, 'align' => 'left'],
        'value' => [['id' => 'el', 'type' => 'paragraph', 'children' => [['text' => $text]]]],
    ]];
}

/** Save a document the way the editor does. */
function saveDocument(User $member, $workspace, $channel, Document $document, string $text): void
{
    test()->actingAs($member)->patch(
        route('chat.documents.update', [$workspace, $channel, $document]),
        [
            'version' => $document->fresh()->version,
            'body' => bodySaying($text),
            'body_text' => $text,
        ],
    );
}

test('de vorige tekst wordt bewaard voordat een opslag hem overschrijft', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Wat er eerst stond'),
        'body_text' => 'Wat er eerst stond',
    ]);

    saveDocument($member, $workspace, $channel, $document, 'Iets heel anders');

    $revision = DocumentRevision::query()->sole();

    // Wat bewaard is, is wat je terug zou willen: de oude tekst, niet de nieuwe.
    expect($revision->body_text)->toBe('Wat er eerst stond')
        ->and($document->fresh()->body_text)->toBe('Iets heel anders');
});

test('een leeg document levert geen revisie op', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    saveDocument($member, $workspace, $channel, $document, 'De eerste woorden');

    // Een document dat nog nooit iets bevatte heeft geen verleden dat een rij
    // waard is, en dat is de gewone staat van eentje van een minuut oud.
    expect(DocumentRevision::query()->count())->toBe(0);
});

test('autosave binnen tien minuten maakt er niet honderd rijen van', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Begin'),
        'body_text' => 'Begin',
    ]);

    foreach (['een', 'twee', 'drie', 'vier', 'vijf'] as $word) {
        saveDocument($member, $workspace, $channel, $document, $word);
    }

    /*
     * Autosave vuurt na 800 ms stilte. Zonder samenvoegen zou een middag
     * schrijven honderden rijen zijn, en een geschiedenis die niemand kan
     * lezen is geen geschiedenis.
     */
    expect(DocumentRevision::query()->count())->toBe(1);
});

test('na tien minuten begint een nieuwe stap', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Begin'),
        'body_text' => 'Begin',
    ]);

    saveDocument($member, $workspace, $channel, $document, 'Eerste sessie');

    $this->travel(RecordDocumentRevision::COALESCE_MINUTES + 1)->minutes();

    saveDocument($member, $workspace, $channel, $document, 'Tweede sessie');

    expect(DocumentRevision::query()->count())->toBe(2)
        ->and(DocumentRevision::query()->newestFirst()->first()->body_text)
        ->toBe('Eerste sessie');
});

test('iemand anders die begint te typen sluit het werk van de vorige af', function () {
    [$member, , $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Everyone);
    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Begin'),
        'body_text' => 'Begin',
    ]);

    saveDocument($member, $workspace, $channel, $document, 'Werk van de eerste');

    /*
     * Zonder deze regel zou een uur schrijven van de een en daarna de herschrijving
     * van de ander in één revisie vallen, en "zet terug wat de eerste had" — precies
     * wat je dan vraagt — zou onmogelijk zijn.
     */
    saveDocument($other, $workspace, $channel, $document, 'Herschreven door de tweede');

    expect(DocumentRevision::query()->count())->toBe(2)
        ->and(DocumentRevision::query()->newestFirst()->first()->body_text)
        ->toBe('Werk van de eerste');
});

test('de revisie staat op naam van wie de tekst schreef, niet van wie hem verving', function () {
    [$member, , $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Everyone);
    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'created_by' => $member->id,
        'updated_by' => $member->id,
        'body' => bodySaying('Van de eerste'),
        'body_text' => 'Van de eerste',
    ]);

    saveDocument($other, $workspace, $channel, $document, 'Van de tweede');

    expect(DocumentRevision::query()->sole()->created_by)->toBe($member->id);
});

test('een hernoeming raakt de geschiedenis niet', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Onaangeroerd'),
        'body_text' => 'Onaangeroerd',
    ]);

    $this->actingAs($member)->patch(
        route('chat.documents.update', [$workspace, $channel, $document]),
        ['version' => 1, 'title' => 'Een andere naam'],
    );

    expect(DocumentRevision::query()->count())->toBe(0);
});

test('een schrijver ziet de geschiedenis', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        // De oude tekst is van wie het document begon; dat is de naam die bij
        // de revisie hoort te staan.
        'created_by' => $member->id,
        'body' => bodySaying('Oud'),
        'body_text' => 'Oud',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Nieuw');

    $this->actingAs($member)
        ->getJson(route('chat.documents.revisions.index', [$workspace, $channel, $document]))
        ->assertOk()
        ->assertJsonPath('revisions.0.excerpt', 'Oud')
        ->assertJsonPath('revisions.0.author', $member->name)
        // Nooit de body: vijftig documenten aan JSON om een lijst met datums te
        // tekenen is precies wat dit endpoint vermijdt.
        ->assertJsonMissingPath('revisions.0.body');
});

test('een versie is te lezen voordat je hem terugzet', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Wat er precies stond'),
        'body_text' => 'Wat er precies stond',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Iets anders');

    $revision = DocumentRevision::query()->sole();

    /*
     * De hele body, en alleen voor de versie waar iemand op klikt: de lijst
     * draagt één regel per versie, want vijftig kopieën van een document
     * downloaden om er één te bekijken is precies wat het paneel vermijdt.
     */
    $this->actingAs($member)
        ->getJson(route('chat.documents.revisions.show', [
            $workspace, $channel, $document, $revision,
        ]))
        ->assertOk()
        ->assertJsonPath('body.blok.value.0.children.0.text', 'Wat er precies stond');
});

test('een gast mag een oude versie ook niet inzien', function () {
    [$member, $guest, $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Members);
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Oud'),
        'body_text' => 'Oud',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Nieuw');
    $revision = DocumentRevision::query()->sole();

    $this->actingAs($guest)
        ->getJson(route('chat.documents.revisions.show', [
            $workspace, $channel, $document, $revision,
        ]))
        ->assertForbidden();
});

test('terugzetten bewaart wat het vervangt, ook binnen het samenvoegvenster', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Het goede werk'),
        'body_text' => 'Het goede werk',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'oeps');

    $revision = DocumentRevision::query()->sole();

    /*
     * Meteen terugzetten, binnen de tien minuten. Het samenvoegvenster bestaat
     * zodat autosave geen rij per aanslag maakt; een terugzetting is het
     * tegenovergestelde en gooit alles sinds de gekozen versie in één keer weg.
     * Als het venster die revisie opslokt is "terugzetten gooit niets weg" een
     * loze belofte — precies wat er in de browser misging.
     */
    $this->actingAs($member)->post(route('chat.documents.revisions.restore', [
        $workspace, $channel, $document, $revision,
    ]));

    expect($document->fresh()->body_text)->toBe('Het goede werk')
        ->and(DocumentRevision::query()->count())->toBe(2)
        ->and(DocumentRevision::query()->newestFirst()->first()->body_text)->toBe('oeps');
});

test('een gast die niet mag schrijven ziet de geschiedenis niet', function () {
    [$member, $guest, $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Members);
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Oud'),
        'body_text' => 'Oud',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Nieuw');

    /*
     * In een oude revisie staat tekst die iemand er bewust uit heeft gehaald.
     * Die tonen aan iedereen die het kanaal mag lezen zou elke verwijdering
     * stilletjes ongedaan maken.
     */
    $this->actingAs($guest)
        ->getJson(route('chat.documents.revisions.index', [$workspace, $channel, $document]))
        ->assertForbidden();
});

test('terugzetten brengt de oude tekst terug', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('De goede versie'),
        'body_text' => 'De goede versie',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Per ongeluk alles weg');

    $revision = DocumentRevision::query()->sole();

    $this->actingAs($member)->post(route('chat.documents.revisions.restore', [
        $workspace, $channel, $document, $revision,
    ]));

    expect($document->fresh()->body_text)->toBe('De goede versie');
});

test('terugzetten gooit niets weg: het nieuwere werk is een stap terug', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Versie een'),
        'body_text' => 'Versie een',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Versie twee');

    $revision = DocumentRevision::query()->sole();
    $this->travel(RecordDocumentRevision::COALESCE_MINUTES + 1)->minutes();

    $this->actingAs($member)->post(route('chat.documents.revisions.restore', [
        $workspace, $channel, $document, $revision,
    ]));

    /*
     * De hele belofte van deze functie. Zet per ongeluk de verkeerde versie
     * terug en wat er stond is zelf ook weer een stap terug — anders is het
     * herstelmechanisme hetzelfde ongeluk, één laag dieper.
     */
    expect($document->fresh()->body_text)->toBe('Versie een')
        ->and(DocumentRevision::query()->newestFirst()->first()->body_text)
        ->toBe('Versie twee');
});

test('terugzetten lukt ook als er intussen iemand een woord heeft getypt', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'body' => bodySaying('Het origineel'),
        'body_text' => 'Het origineel',
    ]);
    saveDocument($member, $workspace, $channel, $document, 'Tussendoor');
    $revision = DocumentRevision::query()->sole();

    // De lijst is even geleden getekend; de versie is sindsdien opgelopen. Dat
    // mag geen weigering zijn, want dit is precies wanneer je hem nodig hebt.
    saveDocument($member, $workspace, $channel, $document, 'En nog iets');

    $this->actingAs($member)
        ->post(route('chat.documents.revisions.restore', [
            $workspace, $channel, $document, $revision,
        ]))
        ->assertSessionHasNoErrors();

    expect($document->fresh()->body_text)->toBe('Het origineel');
});

test('een revisie van een ander document is een 404', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $mine = Document::factory()->create(['channel_id' => $channel->id]);
    $theirs = Document::factory()->create(['channel_id' => $channel->id]);
    $revision = DocumentRevision::factory()
        ->saying('Niet van jou')
        ->create(['document_id' => $theirs->id]);

    $this->actingAs($member)
        ->post(route('chat.documents.revisions.restore', [
            $workspace, $channel, $mine, $revision,
        ]))
        ->assertNotFound();
});

test('het opruimen laat een rustig document zijn spoor houden', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    foreach (range(1, 5) as $number) {
        DocumentRevision::factory()
            ->saying("Versie {$number}")
            ->writtenAt(now()->subDays(PruneDocuments::REVISION_DAYS + $number))
            ->create(['document_id' => $document->id]);
    }

    app(PruneDocuments::class)->handle();

    /*
     * Alles ouder dan de bewaartermijn, en toch blijft het staan: een document
     * dat sinds het voorjaar niet is aangeraakt is juist het lastigst te
     * reconstrueren, en dat is er geen reden voor om zijn geschiedenis te
     * wissen.
     */
    expect($document->revisions()->count())->toBe(5);
});

test('het opruimen kort een lange geschiedenis in', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    foreach (range(1, PruneDocuments::REVISIONS_MAX + 20) as $number) {
        DocumentRevision::factory()
            ->saying("Versie {$number}")
            ->writtenAt(now()->subMinutes($number))
            ->create(['document_id' => $document->id]);
    }

    app(PruneDocuments::class)->handle();

    expect($document->revisions()->count())->toBe(PruneDocuments::REVISIONS_MAX)
        // De nieuwste blijven, want dat is wat iemand terug wil.
        ->and($document->revisions()->first()->body_text)->toBe('Versie 1');
});

test('een document dat definitief weggaat neemt zijn geschiedenis mee', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);
    DocumentRevision::factory()->create(['document_id' => $document->id]);

    $document->forceDelete();

    expect(DocumentRevision::query()->count())->toBe(0);
});
