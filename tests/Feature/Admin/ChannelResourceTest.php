<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Filament\Actions\ToggleChannelArchivedAction;
use App\Filament\Resources\Channels\Pages\EditChannel;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\Channels\Pages\ViewChannel;
use App\Filament\Resources\Channels\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Channels\RelationManagers\WebhooksRelationManager;
use App\Models\Channel;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

test('it lists channels across every workspace', function () {
    $channels = Channel::factory()->count(3)->create();

    Livewire::test(ListChannels::class)
        ->assertCanSeeTableRecords($channels)
        ->assertCanRenderTableColumn('messages_count');
});

test('it filters channels down to one workspace', function () {
    $workspace = Workspace::factory()->create();
    $wanted = Channel::factory()->create(['workspace_id' => $workspace->id]);
    $other = Channel::factory()->create();

    Livewire::test(ListChannels::class)
        ->filterTable('workspace', $workspace->id)
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it archives a channel so nobody can post in it any more', function () {
    $channel = Channel::factory()->create();

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make(ToggleChannelArchivedAction::class)->table($channel));

    expect($channel->refresh()->archived_at)->not->toBeNull();
});

test('it reopens an archived channel', function () {
    $channel = Channel::factory()->create();
    $channel->forceFill(['archived_at' => now()])->save();

    Livewire::test(ListChannels::class)
        ->callAction(TestAction::make(ToggleChannelArchivedAction::class)->table($channel));

    expect($channel->refresh()->archived_at)->toBeNull();
});

test('it locks a channel down to admin posting only', function () {
    $channel = Channel::factory()->create([
        'type' => ChannelType::Public,
        'posting_policy' => ChannelPostingPolicy::Everyone,
    ]);

    Livewire::test(EditChannel::class, ['record' => $channel->getKey()])
        ->fillForm(['posting_policy' => ChannelPostingPolicy::Admins->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($channel->refresh()->posting_policy)->toBe(ChannelPostingPolicy::Admins);
});

test('it renames a channel', function () {
    $channel = Channel::factory()->create(['name' => 'oude-naam', 'slug' => 'oude-naam']);

    Livewire::test(EditChannel::class, ['record' => $channel->getKey()])
        ->fillForm(['name' => 'nieuwe-naam', 'slug' => 'nieuwe-naam'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($channel->refresh()->slug)->toBe('nieuwe-naam');
});

test('it keeps an ordinary user out of the channel list', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/channels')
        ->assertForbidden();
});

test('it lists the webhooks of a channel', function () {
    $channel = Channel::factory()->create();
    $webhook = Webhook::factory()->for($channel)->create(['name' => 'CI', 'bot_name' => 'Buildbot']);
    $elsewhere = Webhook::factory()->create();

    Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->assertCanSeeTableRecords([$webhook])
        ->assertCanNotSeeTableRecords([$elsewhere])
        ->assertCanRenderTableColumn('bot_name');
});

test('it revokes a webhook from the channel record', function () {
    $channel = Channel::factory()->create();
    $webhook = Webhook::factory()->for($channel)->create();

    Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callTableAction('revoke', $webhook);

    expect($webhook->refresh()->revoked_at)->not->toBeNull();
});

/**
 * The hash is not the token, but it is the only thing standing between a
 * copied row and posting rights — so it must not be renderable at all.
 */
test('it never exposes the token hash in the panel', function () {
    $channel = Channel::factory()->create();
    $webhook = Webhook::factory()->for($channel)->create();

    Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->assertDontSee($webhook->token_hash);
});

test('it creates a webhook from the channel record and shows its url', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $channel = Channel::factory()->create();

    $component = Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callAction(
            TestAction::make('create')->table(),
            ['name' => 'CI', 'bot_name' => 'Buildbot'],
        )
        ->assertHasNoActionErrors();

    $webhook = Webhook::firstOrFail();

    expect($webhook->channel_id)->toBe($channel->id)
        ->and($webhook->workspace_id)->toBe($channel->workspace_id)
        ->and($webhook->bot_name)->toBe('Buildbot')
        ->and($webhook->created_by)->toBe($admin->id);

    // The url carries the plain token; the modal that opens after creating is
    // where it is first shown.
    $url = $component->get('freshTokenUrl');

    expect($url)->toContain('/api/webhooks/whk_');

    $token = str($url)->afterLast('/')->value();

    expect(Webhook::hashToken($token))->toBe($webhook->token_hash);
});

test('it lists the members of a channel', function () {
    $channel = Channel::factory()->create();
    $guest = User::factory()->create();
    $outsider = User::factory()->create();

    $channel->workspace->members()->attach($guest->id, [
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->assertCanSeeTableRecords([$guest])
        ->assertCanNotSeeTableRecords([$outsider])
        ->assertCanRenderTableColumn('role');
});

test('it puts a guest into a channel from the panel', function () {
    $channel = Channel::factory()->create(['type' => ChannelType::Private]);
    $guest = User::factory()->create();

    $channel->workspace->members()->attach($guest->id, [
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => $guest->id])
        ->assertHasNoActionErrors();

    expect($channel->members()->whereKey($guest->id)->exists())->toBeTrue();
});

/**
 * A channel is not a way into a workspace: the picker only offers people who
 * are already in it.
 */
test('it does not offer somebody from outside the workspace', function () {
    $channel = Channel::factory()->create();
    $outsider = User::factory()->create();

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callAction(TestAction::make('attach')->table(), ['recordId' => $outsider->id])
        ->assertHasActionErrors();

    expect($channel->members()->whereKey($outsider->id)->exists())->toBeFalse();
});

test('it takes a member back out of a channel', function () {
    $channel = Channel::factory()->create();
    $member = User::factory()->create();

    $channel->workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callTableAction('detach', $member);

    expect($channel->members()->whereKey($member->id)->exists())->toBeFalse();
});

/**
 * The modal renders lazily, so this asserts the way in is there rather than
 * reading the URL out of the markup. What the modal is handed comes from
 * Webhook::url(), which WebhookModelTest pins down.
 */
test('it offers the url of an existing webhook', function () {
    $channel = Channel::factory()->create();
    $webhook = Webhook::factory()->for($channel)->create();

    $webhook->regenerateToken();
    $webhook->save();

    Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->assertActionVisible(TestAction::make('showUrl')->table($webhook));
});

test('it replaces the url of a webhook from the panel', function () {
    $channel = Channel::factory()->create();
    $webhook = Webhook::factory()->for($channel)->create();

    $old = $webhook->regenerateToken();
    $webhook->save();

    Livewire::test(WebhooksRelationManager::class, [
        'ownerRecord' => $channel,
        'pageClass' => ViewChannel::class,
    ])
        ->callAction(TestAction::make('regenerate')->table($webhook));

    expect($webhook->refresh()->token)->not->toBe($old)
        ->and($webhook->token_hash)->toBe(Webhook::hashToken($webhook->token));
});
