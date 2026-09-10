<?php

use App\Enums\ChannelTicketPolicy;
use App\Filament\Resources\Workspaces\Pages\ViewWorkspace;
use App\Filament\Resources\Workspaces\RelationManagers\BacklogConnectionsRelationManager;
use App\Models\BacklogConnection;
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

test('creating a connection stores exactly the secrets the admin typed, not a self-generated pair', function () {
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->for($workspace)
        ->create(['ticket_policy' => ChannelTicketPolicy::Everyone]);

    // These stand in for what Backlog itself printed — passport:client:postduif
    // for the first, the webhook endpoint's one-time reveal for the second.
    // Postduif has no authority to invent either: it did not compute them and
    // is not the one who will check them.
    Livewire::test(BacklogConnectionsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->mountAction('create')
        ->setActionData([
            'backlog_url' => 'https://backlog.example.com',
            'client_id' => 'postduif-client',
            'client_secret' => 'pasted-client-secret-from-backlog',
            'webhook_secret' => 'pasted-webhook-secret-from-backlog',
            'channel_id' => $channel->id,
            'events' => BacklogConnection::EVENTS,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $connection = BacklogConnection::query()->where('workspace_id', $workspace->id)->sole();

    expect($connection->client_secret)->toBe('pasted-client-secret-from-backlog')
        ->and($connection->webhook_secret)->toBe('pasted-webhook-secret-from-backlog');
});

test('editing a connection with the secret fields left blank keeps the pasted secrets', function () {
    // The channel picker only offers ticket-keeping channels (see the DM
    // crash fix above); the factory's plain channel is not one, so it has to
    // be pointed at one explicitly for the edit form's own Select to accept
    // it back unchanged.
    $channel = Channel::factory()->create(['ticket_policy' => ChannelTicketPolicy::Everyone]);
    $connection = BacklogConnection::factory()->create(['channel_id' => $channel->id]);
    $connection->forceFill([
        'client_secret' => 'still-the-one-backlog-has',
        'webhook_secret' => 'still-the-one-backlog-signs-with',
    ])->save();

    Livewire::test(BacklogConnectionsRelationManager::class, [
        'ownerRecord' => $connection->workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->mountTableAction('edit', $connection)
        ->setActionData([
            'backlog_url' => $connection->backlog_url,
            'client_id' => $connection->client_id,
            'client_secret' => '',
            'webhook_secret' => '',
            'channel_id' => $connection->channel_id,
            'events' => $connection->events,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $connection->refresh();

    expect($connection->client_secret)->toBe('still-the-one-backlog-has')
        ->and($connection->webhook_secret)->toBe('still-the-one-backlog-signs-with');
});

test('editing a connection with a new secret actually persists it', function () {
    // Regression: neither secret is in BacklogConnection's Fillable list (on
    // purpose — see the class docblock), which the plain EditAction the
    // create-path never had to rely on. Without a custom ->using(), a typed
    // secret reached update() and Eloquent silently dropped it as a
    // non-fillable key — the form said saved, the database never changed.
    $channel = Channel::factory()->create(['ticket_policy' => ChannelTicketPolicy::Everyone]);
    $connection = BacklogConnection::factory()->create(['channel_id' => $channel->id]);
    $connection->forceFill([
        'client_secret' => 'the-old-client-secret',
        'webhook_secret' => 'the-old-webhook-secret',
    ])->save();

    Livewire::test(BacklogConnectionsRelationManager::class, [
        'ownerRecord' => $connection->workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->mountTableAction('edit', $connection)
        ->setActionData([
            'backlog_url' => $connection->backlog_url,
            'client_id' => $connection->client_id,
            'client_secret' => 'a-freshly-rotated-client-secret',
            'webhook_secret' => 'a-freshly-rotated-webhook-secret',
            'channel_id' => $connection->channel_id,
            'events' => $connection->events,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $connection->refresh();

    expect($connection->client_secret)->toBe('a-freshly-rotated-client-secret')
        ->and($connection->webhook_secret)->toBe('a-freshly-rotated-webhook-secret');
});
