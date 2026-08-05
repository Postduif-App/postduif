<?php

use App\Actions\Workspace\CreateHomeChannel;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Filament\Resources\Workspaces\Pages\CreateWorkspace;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

/**
 * The channel a workspace starts with.
 *
 * Deliberately not hung off Workspace::created, though every workspace
 * somebody makes should have one. Roles are structural and belong to the row;
 * a channel is content, and every test fixture in this suite builds a
 * workspace too — those want it empty. So the action is called from the places
 * a person makes a workspace on purpose, and these tests cover both the action
 * and the first of those places.
 */
it('gives a new workspace somewhere to talk', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $channel = app(CreateHomeChannel::class)->handle($workspace);

    expect($channel)->not->toBeNull()
        ->and($channel->workspace_id)->toBe($workspace->id)
        // Public: a first channel the rest of the workspace cannot see would
        // be a strange thing to start a workspace with.
        ->and($channel->type)->toBe(ChannelType::Public)
        ->and($channel->created_by)->toBe($owner->id);
});

it('puts the owner in it, so it is not a room they are locked out of', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

    $channel = app(CreateHomeChannel::class)->handle($workspace);

    expect($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});

it('names it in the language the workspace was made in', function () {
    $workspace = Workspace::factory()->create();

    app()->setLocale('en');

    $channel = app(CreateHomeChannel::class)->handle($workspace);

    // Slugged on the way in by CreateChannel, so the lang file holds a name
    // rather than an address.
    expect($channel->slug)->toBe('general');
});

it('does not make a second one when it runs twice', function () {
    $workspace = Workspace::factory()->create();

    $first = app(CreateHomeChannel::class)->handle($workspace);
    $second = app(CreateHomeChannel::class)->handle($workspace);

    // It is called from a model-adjacent place, and those are exactly the ones
    // that end up firing twice.
    expect($second->id)->toBe($first->id)
        ->and($workspace->channels()->count())->toBe(1);
});

it('leaves an existing channel of that name alone', function () {
    $workspace = Workspace::factory()->create();

    $existing = app(CreateHomeChannel::class)->handle($workspace);
    $existing->update(['topic' => 'Door iemand aangepast']);

    app(CreateHomeChannel::class)->handle($workspace);

    // Not a unique-constraint violation, and not a reset of what somebody
    // wrote there.
    expect($existing->refresh()->topic)->toBe('Door iemand aangepast');
});

it('gives a workspace made in the admin panel one too', function () {
    $this->actingAs(User::factory()->admin()->create());

    $owner = User::factory()->create();

    Livewire::test(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Nieuwe Werkplek',
            'slug' => 'nieuwe-werkplek',
            'owner_id' => $owner->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $workspace = Workspace::where('slug', 'nieuwe-werkplek')->sole();

    expect($workspace->channels()->count())->toBe(1)
        ->and($workspace->channels()->first()->slug)->toBe('algemeen');
});

it('leaves a workspace built as a test fixture empty', function () {
    // The reason this is not a model event. A fixture that arrived with a
    // conversation in it would quietly change what every other test is about.
    $workspace = Workspace::factory()->create();

    expect($workspace->channels()->count())->toBe(0);
});

/**
 * Entering a workspace that has nothing in it.
 *
 * Every workspace made from now on starts with a channel, but the ones that
 * already exist do not — and the way out of an empty one was a create-channel
 * dialog a member without that right cannot open.
 */
it('builds the first channel when somebody walks into an empty workspace', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->update(['owner_id' => $owner->id]);

    expect($workspace->channels()->count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('chat.index', $workspace))
        ->assertRedirect();

    expect($workspace->channels()->count())->toBe(1)
        ->and($workspace->channels()->first()->slug)->toBe('algemeen');
});

it('does not build one for a member who simply has the right to none', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->update(['owner_id' => $owner->id]);

    // A private channel nobody else is in: the workspace is not empty, so a
    // guest seeing nothing must not be read as there being nothing.
    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
        'created_by' => $owner->id,
    ]);

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'joined_at' => now(),
        'workspace_role_id' => $workspace->roles()->where('key', SystemRole::Guest->value)->value('id'),
    ]);

    $this->actingAs($guest)
        ->get(route('chat.index', $workspace))
        ->assertNotFound();

    expect($workspace->channels()->count())->toBe(1);
});

it('leaves a workspace that already has channels alone', function () {
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->update(['owner_id' => $owner->id]);

    $existing = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->get(route('chat.index', $workspace))
        ->assertRedirect(route('chat.show', [$workspace, $existing]));

    expect($workspace->channels()->count())->toBe(1);
});
