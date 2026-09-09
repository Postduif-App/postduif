<?php

use App\Enums\ChannelTicketPolicy;
use App\Filament\Resources\Workspaces\Pages\ViewWorkspace;
use App\Filament\Resources\Workspaces\RelationManagers\BacklogConnectionsRelationManager;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('opening the create form does not crash on a DM channel with no name', function () {
    $workspace = Workspace::factory()->create();

    // A DM's name/slug/topic are always null (ChannelFactory::direct()).
    // Select::isOptionDisabled() used to be handed that null as a $label
    // and throw a TypeError before the modal ever rendered.
    Channel::factory()->for($workspace)->direct()->create();
    Channel::factory()->for($workspace)
        ->create(['ticket_policy' => ChannelTicketPolicy::Everyone]);

    Livewire::test(BacklogConnectionsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->mountAction('create')
        ->assertActionMounted('create');
});

test('the channel picker only offers channels that keep tickets', function () {
    $ticketed = Channel::factory()->create(['ticket_policy' => ChannelTicketPolicy::Everyone]);
    $untracked = Channel::factory()->create(['ticket_policy' => ChannelTicketPolicy::Disabled]);
    $dm = Channel::factory()->direct()->create();

    expect($ticketed->hasTickets())->toBeTrue()
        ->and($untracked->hasTickets())->toBeFalse()
        ->and($dm->hasTickets())->toBeFalse();
});
