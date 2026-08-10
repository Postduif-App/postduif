<?php

use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelType;
use App\Events\DocumentUpdated;
use App\Features\Documents as DocumentsFeature;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;

test('een lid begint een document en komt er meteen in terecht', function () {
    [$member, , $workspace, $channel] = documentFixture();

    $this->actingAs($member)
        ->post(route('chat.documents.store', [$workspace, $channel]), [
            'title' => 'Afspraken met de klant',
        ])
        ->assertRedirect(route('chat.show', [
            $workspace, $channel, 'view' => 'documents', 'document' => 1,
        ]));

    expect(Document::query()->where('title', 'Afspraken met de klant')->exists())->toBeTrue();
});

test('een document zonder titel wordt geweigerd', function () {
    [$member, , $workspace, $channel] = documentFixture();

    $this->actingAs($member)
        ->post(route('chat.documents.store', [$workspace, $channel]), ['title' => ''])
        ->assertSessionHasErrors('title');
});

test('opslaan gaat door en laat de pagina staan', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['blok-een' => ['type' => 'Paragraph']],
            'body_text' => 'Een regel',
        ])
        ->assertRedirect(route('chat.show', [$workspace, $channel]));

    expect($document->fresh()->version)->toBe(2)
        ->and($document->fresh()->body_text)->toBe('Een regel');
});

test('opslaan met een verlopen versie geeft een foutmelding terug', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->atVersion(3)->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['a' => ['type' => 'Paragraph']],
            'body_text' => 'Van ons',
        ])
        ->assertSessionHasErrors('version');

    expect($document->fresh()->version)->toBe(3);
});

test('een document zonder platte tekst wordt geweigerd', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['a' => ['type' => 'Paragraph']],
        ])
        ->assertSessionHasErrors('body_text');
});

test('een te diep genest document wordt geweigerd', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    // Veertig lagen diep: ruim voorbij wat de editor ooit maakt, en precies het
    // soort invoer waar json_decode het zwaar van krijgt.
    $deep = ['bodem'];
    for ($i = 0; $i < 40; $i++) {
        $deep = [$deep];
    }

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['blok' => $deep],
            'body_text' => 'diep',
        ])
        ->assertSessionHasErrors('body');
});

test('een gast leest mee maar mag niet opslaan', function () {
    [, $guest, $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Members);
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($guest)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['a' => ['type' => 'Paragraph']],
            'body_text' => 'Van de gast',
        ])
        ->assertForbidden();

    // Maar het document staat wél in wat hij te zien krijgt.
    $this->actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'documents']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'documents')
            ->has('documentList', 1));
});

test('staat de feature uit, dan bestaan de routes niet', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    Feature::for($workspace)->deactivate(DocumentsFeature::class);

    // 404 en niet 403: een feature die uitstaat hoort niet te verklappen dat
    // hij bestaat.
    $this->actingAs($member)
        ->post(route('chat.documents.store', [$workspace, $channel]), ['title' => 'Toch'])
        ->assertNotFound();

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
        ])
        ->assertNotFound();
});

test('een documentnummer uit een ander kanaal geeft 404', function () {
    [$member, , $workspace, $channel] = documentFixture();

    $other = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'document_policy' => ChannelDocumentPolicy::Everyone,
    ]);
    $other->members()->attach($member->id, ['joined_at' => now()]);

    $elsewhere = Document::factory()->create(['channel_id' => $other->id]);

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $elsewhere->number]), [
            'version' => 1,
        ])
        ->assertNotFound();
});

test('wie niet in een privékanaal zit komt er niet bij', function () {
    [, , $workspace, $channel] = documentFixture();
    $channel->update(['type' => ChannelType::Private]);
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider);

    $this->actingAs($outsider)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
        ])
        ->assertForbidden();
});

test('de kanaalpagina draagt de documents en de openstaande mee', function () {
    [$member, , $workspace, $channel] = documentFixture();
    Document::factory()->withBody(['Wat er is afgesproken'])->create([
        'channel_id' => $channel->id,
        'title' => 'Afspraken',
    ]);

    $this->actingAs($member)
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'documents', 'document' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'documents')
            ->where('channel.hasDocuments', true)
            ->where('channel.canCreateDocument', true)
            ->has('documentList', 1)
            ->where('documentList.0.title', 'Afspraken')
            ->where('documentList.0.excerpt', 'Wat er is afgesproken')
            ->where('openDocument.number', 1)
            ->where('openDocument.version', 1)
            ->where('openDocument.canEdit', true)
            ->has('openDocument.body'));
});

test('een kanaal zonder documents valt terug op de berichten', function () {
    [$member, , $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Disabled);

    // Een oude link naar ?view=document mag geen leeg tabblad opleveren.
    $this->actingAs($member)
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'documents']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('view', 'messages')
            ->where('channel.hasDocuments', false)
            ->where('documentList', null));
});

test('verwijderen kan alleen door wie het begon', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create([
        'channel_id' => $channel->id,
        'created_by' => $member->id,
    ]);

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $this->actingAs($other)
        ->delete(route('chat.documents.destroy', [$workspace, $channel, $document]))
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('chat.documents.destroy', [$workspace, $channel, $document]))
        ->assertRedirect(route('chat.show', [$workspace, $channel, 'view' => 'documents']));

    expect(Document::query()->count())->toBe(0);
});

test('de kanaalinstelling zet documents aan en uit', function () {
    [$member, , $workspace, $channel] = documentFixture(ChannelDocumentPolicy::Disabled);

    // Wie het kanaal beheert, mag dit zetten.
    $channel->forceFill(['created_by' => $member->id])->save();

    $this->actingAs($member)
        ->patch(route('chat.channels.update', [$workspace, $channel]), [
            'type' => 'public',
            'layout' => 'chat',
            'name' => $channel->name,
            'topic' => $channel->topic ?? '',
            'posting_policy' => $channel->posting_policy->value,
            'document_policy' => 'members',
            'document_announcements' => false,
        ])
        ->assertSessionHasNoErrors();

    $channel->refresh();

    expect($channel->document_policy)->toBe(ChannelDocumentPolicy::Members)
        ->and($channel->document_announcements)->toBeFalse()
        ->and($channel->hasDocuments())->toBeTrue();
});

test('een onbekend documentbeleid wordt geweigerd', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $channel->forceFill(['created_by' => $member->id])->save();

    $this->actingAs($member)
        ->patch(route('chat.channels.update', [$workspace, $channel]), [
            'type' => 'public',
            'layout' => 'chat',
            'name' => $channel->name,
            'topic' => '',
            'posting_policy' => $channel->posting_policy->value,
            'document_policy' => 'iedereen-op-internet',
        ])
        ->assertSessionHasErrors('document_policy');
});

test('een opslag komt niet terug bij de socket die hem stuurde', function () {
    Event::fake([DocumentUpdated::class]);

    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->withHeader('X-Socket-ID', '12345.67890')
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['a' => ['type' => 'Paragraph']],
            'body_text' => 'Een regel',
        ])
        ->assertSessionHasNoErrors();

    /*
     * De socket op het event is wat Reverb gebruikt om de afzender over te
     * slaan. Zonder dat krijgt wie typt zijn eigen autosave terug als "iemand
     * anders heeft dit document bijgewerkt" — precies wat er gebeurde tot
     * app.tsx de X-Socket-ID-header ging meesturen.
     */
    Event::assertDispatched(
        DocumentUpdated::class,
        fn (DocumentUpdated $event): bool => $event->socket === '12345.67890',
    );
});

test('zonder socket-header gaat de uitzending naar iedereen', function () {
    Event::fake([DocumentUpdated::class]);

    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => ['a' => ['type' => 'Paragraph']],
            'body_text' => 'Een regel',
        ]);

    // Een verzoek van buiten de browser — de API, een workflow — heeft geen
    // socket om over te slaan, en dan hoort iedereen het gewoon te horen.
    Event::assertDispatched(
        DocumentUpdated::class,
        fn (DocumentUpdated $event): bool => $event->socket === null,
    );
});

test('een leeg document gaat als object naar de browser en niet als lijst', function () {
    [$member, , $workspace, $channel] = documentFixture();
    Document::factory()->create(['channel_id' => $channel->id]);

    $response = $this->actingAs($member)
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()))
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'documents', 'document' => 1]))
        ->assertOk();

    /*
     * Op de echte JSON en niet op de PHP-array, want daar zit het verschil
     * precies: json_decode('{}', true) geeft [] terug en json_encode maakt daar
     * weer [] van. De editor weigert dat — "Should be an object with blocks.
     * You passed: []" — en klapt er daarna op stuk.
     */
    expect($response->content())
        ->toContain('"body":{}')
        ->not->toContain('"body":[]');
});

test('een gevuld document houdt zijn blokken in de payload', function () {
    [$member, , $workspace, $channel] = documentFixture();
    Document::factory()->withBody(['Een regel'])->create(['channel_id' => $channel->id]);

    $response = $this->actingAs($member)
        ->withHeader('X-Inertia', 'true')
        ->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()))
        ->get(route('chat.show', [$workspace, $channel, 'view' => 'documents', 'document' => 1]))
        ->assertOk();

    expect($response->content())
        ->toContain('"type":"Paragraph"')
        ->toContain('Een regel');
});

test('een lege regel in het document blijft een lege tekst en wordt geen null', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    /*
     * Precies wat de editor stuurt zodra iemand op enter drukt: een tweede blok
     * met een lege tekstnode. Laravel's ConvertEmptyStringsToNull loopt
     * recursief door de request, dus zonder de uitzondering in bootstrap/app.php
     * werd die "" een null — en dan weigert Slate het document te openen en
     * klapt de editor met "can't access property Symbol.iterator".
     */
    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => [
                'blok-een' => [
                    'id' => 'blok-een',
                    'type' => 'Paragraph',
                    'meta' => ['order' => 0, 'depth' => 0],
                    'value' => [[
                        'id' => 'el-een',
                        'type' => 'paragraph',
                        'children' => [['text' => 'Eerste regel']],
                    ]],
                ],
                'blok-twee' => [
                    'id' => 'blok-twee',
                    'type' => 'Paragraph',
                    'meta' => ['order' => 1, 'depth' => 0],
                    'value' => [[
                        'id' => 'el-twee',
                        'type' => 'paragraph',
                        'children' => [['text' => '']],
                    ]],
                ],
            ],
            'body_text' => "Eerste regel\n",
        ])
        ->assertSessionHasNoErrors();

    $stored = $document->fresh()->body;

    expect($stored['blok-twee']['value'][0]['children'][0]['text'])
        ->toBe('')
        ->not->toBeNull();
});

test('witruimte die iemand met opzet typte blijft staan', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $document = Document::factory()->create(['channel_id' => $channel->id]);

    // TrimStrings zou hier de inspringing wegpoetsen.
    $this->actingAs($member)
        ->patch(route('chat.documents.update', [$workspace, $channel, $document]), [
            'version' => 1,
            'body' => [
                'blok' => [
                    'id' => 'blok',
                    'type' => 'Paragraph',
                    'meta' => ['order' => 0, 'depth' => 0],
                    'value' => [[
                        'id' => 'el',
                        'type' => 'paragraph',
                        'children' => [['text' => '    ingesprongen regel']],
                    ]],
                ],
            ],
            'body_text' => '    ingesprongen regel',
        ])
        ->assertSessionHasNoErrors();

    expect($document->fresh()->body['blok']['value'][0]['children'][0]['text'])
        ->toBe('    ingesprongen regel');
});
