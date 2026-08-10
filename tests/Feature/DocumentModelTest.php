<?php

use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Document;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

test('documentnummers lopen op binnen een workspace', function () {
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    $numbers = collect(range(1, 3))
        ->map(fn () => Document::factory()->create(['channel_id' => $channel->id])->number);

    expect($numbers->all())->toBe([1, 2, 3]);
});

test('documents en tickets tellen ieder hun eigen nummers', function () {
    $channel = Channel::factory()->create();

    $document = Document::factory()->create(['channel_id' => $channel->id]);
    $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);

    // Twee losse tellers, dus allebei beginnen ze bij 1. Deelden ze er een, dan
    // zou een van de twee bij 2 beginnen en zou #1 in deze workspace nooit meer
    // eenduidig zijn.
    expect($document->number)->toBe(1)
        ->and($ticket->number)->toBe(1);
});

test('twee workspaces tellen los van elkaar', function () {
    $first = Document::factory()->create(['channel_id' => Channel::factory()->create()->id]);
    $second = Document::factory()->create(['channel_id' => Channel::factory()->create()->id]);

    expect($first->number)->toBe(1)
        ->and($second->number)->toBe(1)
        ->and($first->workspace_id)->not->toBe($second->workspace_id);
});

test('een verwijderd document geeft zijn nummer niet door', function () {
    $channel = Channel::factory()->create();

    Document::factory()->create(['channel_id' => $channel->id])->delete();

    expect(Document::factory()->create(['channel_id' => $channel->id])->number)->toBe(2);
});

test('het document komt er ongeschonden weer uit', function () {
    $document = Document::factory()->withBody(['Eerste alinea', 'Tweede alinea'])->create();

    $stored = $document->fresh();
    $blocks = array_values($stored->body);

    expect($stored->body)->toHaveCount(2)
        ->and($blocks[0]['type'])->toBe('Paragraph')
        ->and($blocks[0]['meta'])->toBe(['order' => 0, 'depth' => 0])
        ->and($blocks[0]['value'][0]['children'][0]['text'])->toBe('Eerste alinea')
        ->and($stored->body_text)->toBe("Eerste alinea\nTweede alinea");
});

test('een leeg document slaat een lege map op en geen lege lijst', function () {
    // In JSON zijn [] en {} niet hetzelfde, en de editor leest zijn waarde als
    // een map van blok-id naar blok. Een lege lijst zou hij niet begrijpen.
    $document = Document::factory()->create();

    expect(json_encode($document->fresh()->getRawOriginal('body')))->toContain('{}');
});

test('een fragment vat de tekst samen zonder de blokken te kennen', function () {
    $document = Document::factory()->withBody(['   Regel   met    veel   ruimte   '])->create();

    expect($document->excerpt())->toBe('Regel met veel ruimte');
});

test('visibleTo laat alleen documents zien uit kanalen die je mag zien', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $open = Channel::factory()->create(['workspace_id' => $workspace->id, 'type' => ChannelType::Public]);
    $closed = Channel::factory()->create(['workspace_id' => $workspace->id, 'type' => ChannelType::Private]);

    $visible = Document::factory()->create(['channel_id' => $open->id]);
    Document::factory()->create(['channel_id' => $closed->id]);

    expect(Document::query()->visibleTo($member)->pluck('id')->all())->toBe([$visible->id]);
});

test('een gast ziet alleen documents uit de kanalen waar hij in zit', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $shared = Channel::factory()->create(['workspace_id' => $workspace->id, 'type' => ChannelType::Public]);
    $shared->members()->attach($guest->id, ['joined_at' => now()]);

    $internal = Channel::factory()->create(['workspace_id' => $workspace->id, 'type' => ChannelType::Public]);

    $reachable = Document::factory()->create(['channel_id' => $shared->id]);
    Document::factory()->create(['channel_id' => $internal->id]);

    // Een openbaar kanaal is voor een gast geen openbaar kanaal: hij is hier
    // alleen voor de kanalen waar hij in gezet is.
    expect(Document::query()->visibleTo($guest)->pluck('id')->all())->toBe([$reachable->id]);
});

test('de lijst zet het laatst bewerkte document bovenaan', function () {
    $channel = Channel::factory()->create();

    $oldest = Document::factory()->create(['channel_id' => $channel->id]);
    $newest = Document::factory()->create(['channel_id' => $channel->id]);

    $oldest->forceFill(['updated_at' => now()->subWeek()])->save();
    $newest->forceFill(['updated_at' => now()])->save();

    expect($channel->documents()->pluck('id')->all())->toBe([$newest->id, $oldest->id]);
});

test('een kanaal houdt pas documents bij als het beleid dat toestaat', function (ChannelDocumentPolicy $policy, bool $expected) {
    $channel = Channel::factory()->create(['document_policy' => $policy]);

    expect($channel->hasDocuments())->toBe($expected);
})->with([
    'uit' => [ChannelDocumentPolicy::Disabled, false],
    'iedereen' => [ChannelDocumentPolicy::Everyone, true],
    'alleen leden' => [ChannelDocumentPolicy::Members, true],
]);

test('een DM houdt nooit documents bij, wat de kolom ook zegt', function () {
    $channel = Channel::factory()->create([
        'type' => ChannelType::Direct,
        'document_policy' => ChannelDocumentPolicy::Everyone,
    ]);

    expect($channel->hasDocuments())->toBeFalse();
});

test('een gast mag niet schrijven als het beleid op leden staat', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'document_policy' => ChannelDocumentPolicy::Members,
    ]);

    expect(ChannelDocumentPolicy::Members->allowsWriting($channel, $member))->toBeTrue()
        ->and(ChannelDocumentPolicy::Members->allowsWriting($channel, $guest))->toBeFalse()
        ->and(ChannelDocumentPolicy::Everyone->allowsWriting($channel, $guest))->toBeTrue();
});

test('het uitdelen van een nummer vergrendelt de workspace-rij', function () {
    $channel = Channel::factory()->create();

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $channel->workspace->claimDocumentNumber();

    // De vergrendeling is de hele reden dat deze methode bestaat: zonder hem
    // lezen twee gelijktijdige aanmaakacties dezelfde teller en botst de
    // tweede op de unieke index in plaats van het volgende nummer te krijgen.
    // Echte gelijktijdigheid valt hier niet te testen — RefreshDatabase houdt
    // alles in één transactie, dus een tweede verbinding ziet niets — dus dit
    // controleert dat het slot daadwerkelijk wordt aangevraagd.
    expect(collect($statements)->filter(
        fn (string $sql): bool => str_contains(strtolower($sql), 'for update')
    ))->not->toBeEmpty();
});
