<?php

use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelType;
use App\Features\Documents as DocumentsFeature;
use App\Models\Channel;
use App\Models\Document;
use Laravel\Pennant\Feature;

test('zoeken vindt een document op zijn tekst', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody(['De sleutel ligt onder de mat bij de buren'])->create([
        'channel_id' => $channel->id,
        'title' => 'Draaiboek',
    ]);

    $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutel']))
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonPath('documents.0.title', 'Draaiboek')
        ->assertJsonPath('documents.0.number', 1);
});

test('het fragment toont waar de treffer staat en niet de eerste regel', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody([
        str_repeat('Inleidende zin die overal hetzelfde is. ', 8).'Hier staat het sleutelwoord.',
    ])->create(['channel_id' => $channel->id]);

    $response = $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutelwoord']));

    expect($response->json('documents.0.snippet'))->toContain('sleutelwoord');
});

test('een document uit een kanaal waar je niet in zit blijft onvindbaar', function () {
    [$member, , $workspace] = documentFixture();

    $closed = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
        'document_policy' => ChannelDocumentPolicy::Everyone,
    ]);

    Document::factory()->withBody(['Het geheime sleutelwoord'])->create([
        'channel_id' => $closed->id,
    ]);

    $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutelwoord']))
        ->assertOk()
        ->assertJsonCount(0, 'documents');
});

test('staat de feature uit, dan levert zoeken geen documents op', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody(['Het sleutelwoord'])->create([
        'channel_id' => $channel->id,
    ]);

    Feature::for($workspace)->deactivate(DocumentsFeature::class);

    // De documents bestaan nog — een feature uitzetten gooit niets weg — maar
    // zoeken zou dan de ene deur zijn die openbleef.
    $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutelwoord']))
        ->assertOk()
        ->assertJsonCount(0, 'documents');
});

test('een kanaal dat documents uitzette levert geen treffers meer', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody(['Het sleutelwoord'])->create([
        'channel_id' => $channel->id,
    ]);

    $channel->update(['document_policy' => ChannelDocumentPolicy::Disabled]);

    // Anders is de treffer een link naar een tabblad dat er niet is.
    $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutelwoord']))
        ->assertJsonCount(0, 'documents');
});

test('geblokkeerde woorden blijven ook in zoekresultaten gemaskeerd', function () {
    [$member, , $workspace, $channel] = documentFixture();
    $workspace->update(['blocked_words' => ['geheim']]);

    Document::factory()->withBody(['Dit is geheim en dat blijft zo'])->create([
        'channel_id' => $channel->id,
        'title' => 'Het geheim',
    ]);

    $response = $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'blijft']));

    expect($response->json('documents.0.title'))->not->toContain('geheim')
        ->and($response->json('documents.0.snippet'))->not->toContain('geheim');
});

test('een verwijderd document is niet meer te vinden', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody(['Het sleutelwoord'])->create([
        'channel_id' => $channel->id,
    ])->delete();

    $this->actingAs($member)
        ->getJson(route('chat.search', [$workspace, 'q' => 'sleutelwoord']))
        ->assertJsonCount(0, 'documents');
});

test('zoeken op een auteur laat documents weg', function () {
    [$member, , $workspace, $channel] = documentFixture();

    Document::factory()->withBody(['Het sleutelwoord'])->create([
        'channel_id' => $channel->id,
    ]);

    // "from:" vraagt wie iets zei, en een document is van het kanaal.
    $this->actingAs($member)
        ->getJson(route('chat.search', [
            $workspace,
            'q' => 'sleutelwoord',
            'from' => $member->username ?? $member->name,
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'documents');
});
