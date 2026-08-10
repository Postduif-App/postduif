<?php

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\PresentDocument;
use App\Actions\Documents\UpdateDocument;
use App\Enums\ChannelDocumentPolicy;
use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

test('een document beginnen levert een leeg document op versie 1', function () {
    [$member, , , $channel] = documentFixture();

    $document = app(CreateDocument::class)->handle($channel, $member, 'Afspraken met de klant');

    expect($document->number)->toBe(1)
        ->and($document->title)->toBe('Afspraken met de klant')
        ->and($document->version)->toBe(1)
        ->and($document->body)->toBe([])
        ->and($document->body_text)->toBe('')
        ->and($document->created_by)->toBe($member->id);
});

test('het kanaal hoort dat er een document is begonnen', function () {
    [$member, , , $channel] = documentFixture();

    app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');

    expect($channel->messages()->latest('id')->first()?->body)
        ->toContain('Draaiboek')
        ->toContain($member->name);
});

test('een kanaal dat aankondigingen uit heeft staan blijft stil', function () {
    [$member, , , $channel] = documentFixture();
    $channel->update(['document_announcements' => false]);

    app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');

    expect($channel->messages()->count())->toBe(0);
});

test('opslaan hoogt de versie op en onthoudt wie het deed', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');

    $other = User::factory()->create();
    joinWorkspace($channel->workspace, $other);

    $saved = app(UpdateDocument::class)->handle(
        document: $document,
        editor: $other,
        expectedVersion: 1,
        body: ['blok' => ['type' => 'Paragraph']],
        bodyText: 'Eerste regel',
    );

    expect($saved->version)->toBe(2)
        ->and($saved->updated_by)->toBe($other->id)
        ->and($saved->body_text)->toBe('Eerste regel');
});

test('opslaan met een verlopen versie weigert in plaats van te overschrijven', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');

    // Iemand anders was eerder: de versie staat nu op 2.
    app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['a' => 1], bodyText: 'Van de ander');

    // En wij sturen nog steeds de 1 mee die we bij het openen kregen.
    app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['b' => 2], bodyText: 'Van ons');
})->throws(ValidationException::class);

test('een geweigerde opslag laat het werk van de ander staan', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');

    app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['a' => 1], bodyText: 'Van de ander');

    try {
        app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['b' => 2], bodyText: 'Van ons');
    } catch (ValidationException) {
        // Het punt van deze test staat hieronder.
    }

    expect($document->fresh()->body_text)->toBe('Van de ander');
});

test('hernoemen zegt het in het kanaal, gewoon opslaan niet', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Oude naam');

    $before = $channel->messages()->count();

    app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['a' => 1], bodyText: 'Alleen tekst');
    expect($channel->messages()->count())->toBe($before);

    app(UpdateDocument::class)->handle($document->fresh(), $member, 2, title: 'Nieuwe naam');

    expect($channel->messages()->count())->toBe($before + 1)
        ->and($channel->messages()->latest('id')->first()?->body)->toContain('Nieuwe naam');
});

test('verwijderen is zacht en houdt het nummer bezet', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Weg ermee');

    app(DeleteDocument::class)->handle($document, $member);

    expect(Document::query()->count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1)
        ->and(app(CreateDocument::class)->handle($channel, $member, 'De volgende')->number)->toBe(2);
});

test('de lijst laat het document weg en de detailweergave niet', function () {
    [$member, , , $channel] = documentFixture();
    $document = Document::factory()->withBody(['Een regel tekst'])->create(['channel_id' => $channel->id]);

    $summary = app(PresentDocument::class)->summary($document);
    $full = app(PresentDocument::class)->handle($document, $member);

    expect($summary)->not->toHaveKey('body')
        ->and($summary['excerpt'])->toBe('Een regel tekst')
        ->and($full)->toHaveKey('body')
        ->and($full['version'])->toBe(1)
        ->and($full['canEdit'])->toBeTrue();
});

test('geblokkeerde woorden verdwijnen uit de titel maar niet uit het document', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $workspace->update(['blocked_words' => ['geheim']]);

    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'title' => 'Het geheim van de smid',
    ]);

    $full = app(PresentDocument::class)->handle($document, $member);

    // De titel gaat door de blokkeerlijst, het document niet: dat is een
    // blokkenboom en er doorheen lopen zou een tweede, slechtere versie van
    // de serializer van de editor betekenen.
    expect($full['title'])->not->toContain('geheim')
        ->and($full)->toHaveKey('body');
});

test('een gast mag lezen maar niet schrijven als het beleid op leden staat', function () {
    [$member, $guest, , $channel] = documentFixture(ChannelDocumentPolicy::Members);
    $document = app(CreateDocument::class)->handle($channel, $member, 'Intern');

    expect($guest->can('view', $document))->toBeTrue()
        ->and($guest->can('update', $document))->toBeFalse()
        ->and($guest->can('create', [Document::class, $channel]))->toBeFalse()
        ->and($member->can('update', $document))->toBeTrue();
});

test('een gast schrijft wel mee als het beleid iedereen toelaat', function () {
    [, $guest, , $channel] = documentFixture(ChannelDocumentPolicy::Everyone);

    expect($guest->can('create', [Document::class, $channel]))->toBeTrue();
});

test('niemand schrijft in een gearchiveerd kanaal', function () {
    [$member, , , $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Klaar');

    // forceFill: archived_at staat bewust buiten de Fillable van Channel,
    // dus update() zou hier stilletjes niets doen.
    $channel->forceFill(['archived_at' => now()])->save();

    expect($member->can('update', $document->fresh()))->toBeFalse()
        ->and($member->can('create', [Document::class, $channel->fresh()]))->toBeFalse();
});

test('wie er niet bij hoort ziet niets', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $outsider = User::factory()->create();

    expect($outsider->can('view', $document))->toBeFalse()
        ->and($outsider->can('update', $document))->toBeFalse();
});

test('alleen wie het begon of de workspace beheert mag het weggooien', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = app(CreateDocument::class)->handle($channel, $member, 'Van mij');

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    expect($member->can('delete', $document))->toBeTrue()
        ->and($other->can('delete', $document))->toBeFalse()
        // Meeschrijven mag wel: een document is het geheugen van het kanaal, niet
        // het eigendom van wie toevallig als eerste begon.
        ->and($other->can('update', $document))->toBeTrue();
});

test('een document dat verandert laat het kanaal weten dat er iets bewoog', function () {
    Event::fake([DocumentUpdated::class]);

    [$member, , , $channel] = documentFixture();

    $document = app(CreateDocument::class)->handle($channel, $member, 'Draaiboek');
    app(UpdateDocument::class)->handle($document->fresh(), $member, 1, body: ['a' => 1], bodyText: 'Tekst');
    app(DeleteDocument::class)->handle($document->fresh(), $member);

    // Aanmaken, opslaan en verwijderen: alle drie veranderen wat het kanaal
    // te zien hoort te krijgen.
    Event::assertDispatchedTimes(DocumentUpdated::class, 3);
});

test('de uitzending draagt alleen het nummer en niet het document', function () {
    [, , , $channel] = documentFixture();
    $document = Document::factory()->withBody(['Iets vertrouwelijks'])->create([
        'channel_id' => $channel->id,
    ]);

    $payload = (new DocumentUpdated($document))->broadcastWith();

    // Het document gaat nooit over de lijn. Bij autosave zou dat iemands halve
    // zin elke paar seconden naar het hele kanaal sturen.
    expect($payload)->toBe([
        'channelId' => $channel->id,
        'number' => $document->number,
    ])->and(json_encode($payload))->not->toContain('Iets vertrouwelijks');
});
