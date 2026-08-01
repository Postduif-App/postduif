<?php

use App\Enums\BroadcastMentionPolicy;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\Workspaces\RelationManagers\MembersRelationManager;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('it lists every workspace on the platform', function () {
    $workspaces = Workspace::factory()->count(3)->create();

    Livewire::test(ListWorkspaces::class)
        ->assertCanSeeTableRecords($workspaces)
        ->assertCanRenderTableColumn('members_count')
        ->assertCanRenderTableColumn('channels_count');
});

test('it searches workspaces by slug', function () {
    $wanted = Workspace::factory()->create(['slug' => 'de-gezochte-workspace']);
    $other = Workspace::factory()->create();

    Livewire::test(ListWorkspaces::class)
        ->searchTable('de-gezochte')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it changes who may use a broadcast mention', function () {
    $workspace = Workspace::factory()->create([
        'broadcast_mentions' => BroadcastMentionPolicy::Admins,
    ]);

    Livewire::test(EditWorkspace::class, ['record' => $workspace->slug])
        ->fillForm(['broadcast_mentions' => BroadcastMentionPolicy::Nobody->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->broadcast_mentions)->toBe(BroadcastMentionPolicy::Nobody);
});

test('it manages the list of blocked words', function () {
    $workspace = Workspace::factory()->create();

    Livewire::test(EditWorkspace::class, ['record' => $workspace->slug])
        ->fillForm(['blocked_words' => ['Sukkel', ' sukkel ', 'SUL', '']])
        ->call('save')
        ->assertHasNoFormErrors();

    // Normalised on save, so the matcher never sees the same word twice in
    // three spellings.
    expect($workspace->refresh()->blocked_words)->toBe(['sukkel', 'sul']);
});

test('it refuses a slug that is already taken', function () {
    Workspace::factory()->create(['slug' => 'bezet']);
    $workspace = Workspace::factory()->create();

    Livewire::test(EditWorkspace::class, ['record' => $workspace->slug])
        ->fillForm(['slug' => 'bezet'])
        ->call('save')
        ->assertHasFormErrors(['slug']);
});

test('it detaches a member from a workspace', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->assertCanSeeTableRecords([$member])
        ->callTableAction('detach', $member);

    expect($workspace->refresh()->hasMember($member))->toBeFalse();
});

test('it keeps the owner attached to the workspace', function () {
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($workspace->owner_id, [
        'role' => WorkspaceRole::Owner->value,
        'joined_at' => now(),
    ]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->assertTableActionDisabled('detach', $workspace->owner);
});

test('it archives a channel from the workspace page', function () {
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->callTableAction('toggleArchived', $channel);

    expect($channel->refresh()->archived_at)->not->toBeNull();
});

test('it keeps an ordinary user out of the workspace list', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/workspaces')
        ->assertForbidden();
});
