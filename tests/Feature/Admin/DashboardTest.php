<?php

use App\Filament\Widgets\LatestWorkspaces;
use App\Filament\Widgets\PlatformStats;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('it counts what is on the platform', function () {
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);
    Message::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    Livewire::test(PlatformStats::class)
        ->assertSee('Workspaces')
        ->assertSee('Berichten')
        ->assertSee('2 nieuw in 7 dagen');
});

test('it does not count a workspace from last month as new', function () {
    Workspace::factory()->create()->forceFill(['created_at' => now()->subMonth()])->save();

    Livewire::test(PlatformStats::class)
        ->assertSee('0 nieuw in 7 dagen');
});

test('it shows the workspaces that were created last', function () {
    $newest = Workspace::factory()->create(['name' => 'De nieuwste workspace']);

    Livewire::test(LatestWorkspaces::class)
        ->assertCanSeeTableRecords([$newest])
        ->assertSee('De nieuwste workspace');
});

test('it renders the dashboard for a moderator', function () {
    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('Workspaces');
});
