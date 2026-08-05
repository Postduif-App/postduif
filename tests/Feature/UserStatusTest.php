<?php

use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('starts everybody off available and without a status', function () {
    $user = User::factory()->create();

    expect($user->availability)->toBe(Availability::Available)
        ->and($user->status_text)->toBeNull()
        ->and($user->status_emoji)->toBeNull()
        ->and($user->recent_statuses)->toBe([]);
});

it('sets a status and remembers it', function () {
    $user = User::factory()->create();

    app(SetStatus::class)->handle($user, '🍕', 'Lunchen', Availability::Away);

    expect($user->fresh())
        ->status_emoji->toBe('🍕')
        ->status_text->toBe('Lunchen')
        ->availability->toBe(Availability::Away)
        ->recent_statuses->toEqual([['emoji' => '🍕', 'text' => 'Lunchen']]);
});

it('puts the newest status first without repeating one', function () {
    $user = User::factory()->create();
    $setStatus = app(SetStatus::class);

    $setStatus->handle($user, '🍕', 'Lunchen', Availability::Away);
    $setStatus->handle($user, '📞', 'In gesprek', Availability::DoNotDisturb);
    $setStatus->handle($user, '🍕', 'Lunchen', Availability::Away);

    // toEqual, not toBe: jsonb hands the keys back in whatever order it likes.
    expect($user->fresh()->recent_statuses)->toEqual([
        ['emoji' => '🍕', 'text' => 'Lunchen'],
        ['emoji' => '📞', 'text' => 'In gesprek'],
    ]);
});

it('keeps only the last handful', function () {
    $user = User::factory()->create();
    $setStatus = app(SetStatus::class);

    foreach (range(1, SetStatus::RECENT_LIMIT + 3) as $number) {
        $setStatus->handle($user, null, "Status {$number}", Availability::Available);
    }

    expect($user->fresh()->recent_statuses)
        ->toHaveCount(SetStatus::RECENT_LIMIT)
        ->and($user->fresh()->recent_statuses[0]['text'])
        ->toBe('Status '.(SetStatus::RECENT_LIMIT + 3));
});

it('clears both halves of a status at once, and keeps the shortcuts', function () {
    $user = User::factory()->create();
    $setStatus = app(SetStatus::class);

    $setStatus->handle($user, '🍕', 'Lunchen', Availability::Away);
    $setStatus->handle($user, null, null, Availability::Available);

    expect($user->fresh())
        ->status_emoji->toBeNull()
        ->status_text->toBeNull()
        ->availability->toBe(Availability::Available)
        // Clearing your status is not the same as forgetting you ever had one.
        ->recent_statuses->toEqual([['emoji' => '🍕', 'text' => 'Lunchen']]);
});

it('treats whitespace as no status at all', function () {
    $user = User::factory()->create();

    app(SetStatus::class)->handle($user, '  ', "  \n ", Availability::Available);

    expect($user->fresh())
        ->status_text->toBeNull()
        ->status_emoji->toBeNull()
        ->recent_statuses->toBe([]);
});

it('lets only do-not-disturb hold back a notification', function (Availability $availability, bool $allowed) {
    expect($availability->allowsNotifications())->toBe($allowed);
})->with([
    'beschikbaar' => [Availability::Available, true],
    'afwezig' => [Availability::Away, true],
    'niet storen' => [Availability::DoNotDisturb, false],
]);

it('sets a status from the menu', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('status.update'), [
            'status_emoji' => '📅',
            'status_text' => 'In vergadering',
            'availability' => Availability::DoNotDisturb->value,
        ])
        ->assertRedirect();

    expect($user->fresh())
        ->status_text->toBe('In vergadering')
        ->availability->toBe(Availability::DoNotDisturb);
});

it('clears a status from the menu', function () {
    $user = User::factory()->create();
    app(SetStatus::class)->handle($user, '📅', 'In vergadering', Availability::DoNotDisturb);

    actingAs($user)
        ->patch(route('status.update'), [
            'status_emoji' => '',
            'status_text' => '',
            'availability' => Availability::Available->value,
        ])
        ->assertRedirect();

    expect($user->fresh())
        ->status_text->toBeNull()
        ->availability->toBe(Availability::Available);
});

it('refuses a status longer than the field allows', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('status.update'), [
            'status_text' => str_repeat('a', 101),
            'availability' => Availability::Available->value,
        ])
        ->assertSessionHasErrors('status_text');
});

it('refuses an availability that does not exist', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('status.update'), [
            'status_text' => 'Aan het werk',
            'availability' => 'op-de-maan',
        ])
        ->assertSessionHasErrors('availability');

    expect($user->fresh()->availability)->toBe(Availability::Available);
});

it('refuses a status change from somebody who is not signed in', function () {
    $this->patch(route('status.update'), [
        'availability' => Availability::Away->value,
    ])->assertRedirect(route('login'));
});

it('hands the picker its options on every screen', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('auth.availabilityOptions', count(Availability::cases()))
            ->where('auth.availabilityOptions.0.label', Availability::Available->label())
        );
});

it('carries a member status into the channel payload', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $workspace = workspaceWithMember($user);
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $channel = channelWithMember($workspace, $user);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    app(SetStatus::class)->handle($colleague, '🎧', 'Aan het focussen', Availability::DoNotDisturb);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.members.1.statusText', 'Aan het focussen')
            ->where('channel.members.1.statusEmoji', '🎧')
            ->where('channel.members.1.availability', Availability::DoNotDisturb->value)
        );
});

it('puts the other person status on a one-on-one in the sidebar', function () {
    $user = User::factory()->create();
    $colleague = User::factory()->create();
    $workspace = workspaceWithMember($user);
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    $dm = Channel::factory()->direct()->create(['workspace_id' => $workspace->id]);
    $dm->members()->attach([$user->id, $colleague->id], ['joined_at' => now()]);

    app(SetStatus::class)->handle($colleague, '🌴', 'Op vakantie', Availability::Away);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $dm]))
        ->assertInertia(fn ($page) => $page
            ->where('directMessages.0.status.text', 'Op vakantie')
            ->where('directMessages.0.status.availability', Availability::Away->value)
        );
});

it('leaves a channel row without a status, because a room has none', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    app(SetStatus::class)->handle($user, '🌴', 'Op vakantie', Availability::Away);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channels.0.status', null));
});
