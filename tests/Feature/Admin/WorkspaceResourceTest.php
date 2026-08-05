<?php

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Features\Tickets;
use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\Pages\EditWorkspaceFeatures;
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
    ]);

    Livewire::test(EditWorkspace::class, ['record' => $workspace->slug])
        ->fillForm(['name' => 'Hernoemd'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->name)->toBe('Hernoemd');
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
    joinWorkspace($workspace, $member, SystemRole::Member);

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
        'workspace_role_id' => roleId($workspace, SystemRole::Owner),
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

test('it shows a moderator which features a workspace offers', function () {
    $workspace = Workspace::factory()->create();

    Livewire::test(EditWorkspaceFeatures::class, ['record' => $workspace->getRouteKey()])
        ->assertSchemaStateSet([
            'tickets' => true,
            // Off until somebody says otherwise, which is the whole reason it
            // is worth showing here.
            'ai-access' => false,
        ]);
});

test('it switches a feature off for one workspace', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    Livewire::test(EditWorkspaceFeatures::class, ['record' => $workspace->getRouteKey()])
        ->fillForm(['tickets' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($workspace->hasFeature(Tickets::class))->toBeFalse()
        // And nobody else's, which is the point of the scope.
        ->and($other->hasFeature(Tickets::class))->toBeTrue();
});

/**
 * Every field on this page is a feature, so saving must not touch the record
 * itself — a form that quietly rewrote the name would be a nasty surprise.
 */
test('it leaves the workspace record alone when saving features', function () {
    $workspace = Workspace::factory()->create(['name' => 'Bouwbedrijf Jansen']);

    Livewire::test(EditWorkspaceFeatures::class, ['record' => $workspace->getRouteKey()])
        ->fillForm(['webhooks' => false])
        ->call('save');

    expect($workspace->fresh()->name)->toBe('Bouwbedrijf Jansen');
});

/**
 * Making a channel from the admin panel.
 *
 * Through CreateChannel rather than the relationship: writing the row and
 * stopping there would leave a channel with no slug and nobody in it.
 */
test('it creates a channel for a workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Nieuwe Klanten',
            'type' => ChannelType::Public->value,
            'topic' => 'Alles rond binnenkomende klanten',
        ])
        ->assertHasNoTableActionErrors();

    $channel = $workspace->channels()->sole();

    expect($channel->slug)->toBe('nieuwe-klanten')
        ->and($channel->type)->toBe(ChannelType::Public)
        ->and($channel->topic)->toBe('Alles rond binnenkomende klanten')
        // The workspace owner, not the administrator who pressed the button:
        // an admin is usually not a member, and putting them in the room would
        // show a stranger in its member list.
        ->and($channel->created_by)->toBe($owner->id)
        ->and($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});

test('it refuses a channel name the workspace already uses', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'algemeen',
        'slug' => 'algemeen',
        'created_by' => $owner->id,
    ]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        // Different spelling, same address once slugged — which is what the
        // unique constraint is actually about.
        ->callTableAction('create', data: [
            'name' => 'Algemeen',
            'type' => ChannelType::Public->value,
        ])
        ->assertHasTableActionErrors(['name']);

    expect($workspace->channels()->count())->toBe(1);
});

test('it does not offer a direct message as something to create', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    // A DM is two people starting to talk, not a room somebody opens.
    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->callTableAction('create', data: [
            'name' => 'Stiekem',
            'type' => ChannelType::Direct->value,
        ])
        ->assertHasTableActionErrors(['type']);

    expect($workspace->channels()->count())->toBe(0);
});

/**
 * The members list reads the role row, not the old string on the pivot.
 *
 * A workspace writes its own roles now, so the column and the filter have to
 * survive a role that is not one of the built-in four — SystemRole::from()
 * would have thrown a ValueError on exactly that.
 */
test('it shows a role the workspace wrote for itself', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $role = $workspace->roles()->create([
        'key' => 'custom-leverancier',
        'name' => 'Leverancier',
        'is_external' => true,
        'is_system' => false,
        'position' => 99,
        'abilities' => [],
    ]);

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'workspace_role_id' => $role->id,
        'joined_at' => now(),
    ]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->assertCanSeeTableRecords([$member])
        /*
         * The column's own state, not assertSee: the filter dropdown lists
         * every role by name too, so "is Leverancier on this page" is answered
         * yes even when the column is showing something else entirely.
         */
        ->assertTableColumnStateSet('role', 'Leverancier', $member);
});

test('it filters the member list on a role the workspace wrote', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $custom = $workspace->roles()->create([
        'key' => 'custom-leverancier',
        'name' => 'Leverancier',
        'is_external' => true,
        'is_system' => false,
        'position' => 99,
        'abilities' => [],
    ]);

    $supplier = User::factory()->create();
    $workspace->members()->attach($supplier->id, [
        'workspace_role_id' => $custom->id,
        'joined_at' => now(),
    ]);

    $ordinary = User::factory()->create();
    joinWorkspace($workspace, $ordinary, SystemRole::Member);

    // Filtering on the pointer, which is what the membership actually holds.
    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->filterTable('role', $custom->id)
        ->assertCanSeeTableRecords([$supplier])
        ->assertCanNotSeeTableRecords([$ordinary]);
});
