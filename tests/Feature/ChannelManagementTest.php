<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('creates a public channel and puts the creator in it', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
            'topic' => 'Campagnes en cijfers',
        ])
        ->assertRedirect();

    $channel = Channel::firstWhere('slug', 'marketing');

    expect($channel)->not->toBeNull()
        ->and($channel->workspace_id)->toBe($workspace->id)
        ->and($channel->type)->toBe(ChannelType::Public)
        ->and($channel->topic)->toBe('Campagnes en cijfers')
        ->and($channel->created_by)->toBe($user->id)
        // Without this the creator would own a room they cannot post in.
        ->and($channel->members()->whereKey($user->id)->exists())->toBeTrue();
});

it('sends the creator straight into the new channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
        ])
        ->assertRedirect(route('chat.show', [
            $workspace,
            Channel::firstWhere('slug', 'marketing'),
        ], absolute: false));
});

it('slugs the name so casing and spaces cannot create twins', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)->post(route('chat.channels.store', $workspace), [
        'name' => 'Nieuwe Klanten',
        'type' => 'public',
    ])->assertRedirect();

    expect(Channel::firstWhere('slug', 'nieuwe-klanten'))->not->toBeNull();

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'nieuwe klanten',
            'type' => 'public',
        ])
        ->assertSessionHasErrors('name');

    expect(Channel::count())->toBe(1);
});

it('allows the same channel name in a different workspace', function () {
    $user = User::factory()->create();
    $first = workspaceWithMember($user);
    $second = workspaceWithMember($user);

    foreach ([$first, $second] as $workspace) {
        actingAs($user)->post(route('chat.channels.store', $workspace), [
            'name' => 'algemeen',
            'type' => 'public',
        ])->assertRedirect();
    }

    expect(Channel::where('slug', 'algemeen')->count())->toBe(2);
});

it('refuses a channel from someone outside the workspace', function () {
    $outsider = User::factory()->create();
    $workspace = workspaceWithMember(User::factory()->create());

    actingAs($outsider)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'inbraak',
            'type' => 'public',
        ])
        ->assertForbidden();

    expect(Channel::count())->toBe(0);
});

it('refuses to create a direct message as a channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'stiekem',
            'type' => 'dm',
        ])
        ->assertSessionHasErrors('type');

    expect(Channel::count())->toBe(0);
});

it('hides a new private channel from the rest of the workspace', function () {
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $other = User::factory()->create();
    $workspace->members()->attach($other->id, ['role' => 'member', 'joined_at' => now()]);
    $home = channelWithMember($workspace, $other);

    actingAs($creator)->post(route('chat.channels.store', $workspace), [
        'name' => 'directie',
        'type' => 'private',
    ])->assertRedirect();

    actingAs($other)
        ->get(route('chat.show', [$workspace, $home]))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)
                ->doesntContain('name', 'directie')
            )
        );
});

it('lets a workspace member join a public channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse();

    actingAs($user)
        ->post(route('chat.channels.join', [$workspace, $channel]))
        ->assertRedirect();

    expect($channel->members()->whereKey($user->id)->exists())->toBeTrue();
});

it('does not duplicate membership when joining twice', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($user)->post(route('chat.channels.join', [$workspace, $channel]));
    actingAs($user)->post(route('chat.channels.join', [$workspace, $channel]));

    expect($channel->members()->whereKey($user->id)->count())->toBe(1);
});

it('refuses to let anyone join a private channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->post(route('chat.channels.join', [$workspace, $channel]))
        ->assertForbidden();

    expect($channel->members()->whereKey($user->id)->exists())->toBeFalse();
});

it('refuses to let an outsider join a public channel', function () {
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($outsider)
        ->post(route('chat.channels.join', [$workspace, $channel]))
        ->assertForbidden();
});

it('unlocks posting once you have joined', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    $payload = fn () => [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Hallo',
    ];

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), $payload())
        ->assertForbidden();

    actingAs($user)->post(route('chat.channels.join', [$workspace, $channel]));

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), $payload())
        ->assertRedirect();
});
