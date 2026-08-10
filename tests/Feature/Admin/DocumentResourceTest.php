<?php

use App\Models\Channel;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->moderator = User::factory()->admin()->create();
    $this->workspace = Workspace::factory()->create();
    $this->channel = Channel::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('de beheerpagina toont documents uit elke workspace', function () {
    $here = Document::factory()->create([
        'channel_id' => $this->channel->id,
        'title' => 'Draaiboek hier',
    ]);
    $elsewhere = Document::factory()->create(['title' => 'Draaiboek elders']);

    // De hele reden dat deze weergave bestaat: de vraag die geen enkele losse
    // workspace kan beantwoorden.
    $this->actingAs($this->moderator)
        ->get('/admin/documents')
        ->assertSuccessful()
        ->assertSee($here->title)
        ->assertSee($elsewhere->title);
});

test('de detailpagina toont de tekst en niet de blokkenboom', function () {
    $document = Document::factory()->withBody(['Wat er precies is afgesproken'])->create([
        'channel_id' => $this->channel->id,
    ]);

    $this->actingAs($this->moderator)
        ->get("/admin/documents/{$document->getKey()}")
        ->assertSuccessful()
        ->assertSee('Wat er precies is afgesproken')
        // De JSON zou hier onleesbaar zijn en de blokkenboom renderen zou een
        // tweede renderer in PHP betekenen die de plugins moet bijhouden.
        ->assertDontSee('Paragraph');
});

test('er is geen weg om een document hier te bewerken of aan te maken', function () {
    /*
     * Op de routes zelf, niet op een verzoek. /admin/documents/create zou door
     * de {record}-route worden opgevangen en 'create' als id proberen te lezen,
     * en dan test je de foutafhandeling van Postgres in plaats van of de pagina
     * bestaat.
     *
     * De editor bestaat niet in dit paneel, en een textarea over een blokkenboom
     * is geen slechtere manier om te bewerken maar een manier om te beschadigen.
     */
    $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName());

    expect($names)->not->toContain('filament.admin.resources.documents.edit')
        ->and($names)->not->toContain('filament.admin.resources.documents.create')
        ->and($names)->toContain('filament.admin.resources.documents.view');
});

test('wie geen moderator is komt er niet in', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/documents')
        ->assertForbidden();
});
