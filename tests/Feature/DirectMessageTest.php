<?php

use App\Actions\Chat\StartDirectMessage;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * Two colleagues in the same workspace.
 *
 * @return array{0: User, 1: User, 2: Workspace}
 */
function twoMembers(): array
{
    $initiator = User::factory()->create();
    $workspace = workspaceWithMember($initiator);
    $recipient = User::factory()->create();
    joinWorkspace($workspace, $recipient, SystemRole::Member);

    return [$initiator, $recipient, $workspace];
}

it('creates a direct message channel with both people in it', function () {
    [$initiator, $recipient, $workspace] = twoMembers();

    $channel = app(StartDirectMessage::class)->handle($workspace, $initiator, $recipient);

    expect($channel->type)->toBe(ChannelType::Direct)
        ->and($channel->name)->toBeNull()
        ->and($channel->slug)->toBeNull()
        ->and($channel->members()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$initiator->id, $recipient->id])->sort()->values()->all());
});

it('reuses the existing conversation instead of opening a second one', function () {
    [$initiator, $recipient, $workspace] = twoMembers();

    $first = app(StartDirectMessage::class)->handle($workspace, $initiator, $recipient);
    $second = app(StartDirectMessage::class)->handle($workspace, $recipient, $initiator);

    expect($second->id)->toBe($first->id)
        ->and($workspace->channels()->where('type', ChannelType::Direct)->count())->toBe(1);
});

it('does not mistake a larger conversation for the one-on-one', function () {
    [$initiator, $recipient, $workspace] = twoMembers();
    $third = User::factory()->create();

    $group = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Direct,
        'name' => null,
        'slug' => null,
    ]);
    $group->members()->attach(
        [$initiator->id, $recipient->id, $third->id],
        ['joined_at' => now()],
    );

    $channel = app(StartDirectMessage::class)->handle($workspace, $initiator, $recipient);

    expect($channel->id)->not->toBe($group->id)
        ->and($channel->members()->count())->toBe(2);
});

it('refuses a conversation with yourself', function () {
    [$initiator, , $workspace] = twoMembers();

    app(StartDirectMessage::class)->handle($workspace, $initiator, $initiator);
})->throws(InvalidArgumentException::class);

it('opens the conversation from the endpoint', function () {
    [$initiator, $recipient, $workspace] = twoMembers();

    actingAs($initiator)
        ->post(route('chat.directs.store', $workspace), ['user_id' => $recipient->id])
        ->assertRedirect();

    $channel = $workspace->channels()->where('type', ChannelType::Direct)->sole();

    expect($channel->members()->whereKey($recipient->id)->exists())->toBeTrue();
});

it('lands a second attempt in the same conversation', function () {
    [$initiator, $recipient, $workspace] = twoMembers();

    actingAs($initiator)->post(route('chat.directs.store', $workspace), ['user_id' => $recipient->id]);
    $first = $workspace->channels()->where('type', ChannelType::Direct)->sole();

    actingAs($initiator)
        ->post(route('chat.directs.store', $workspace), ['user_id' => $recipient->id])
        ->assertRedirect(route('chat.show', [$workspace, $first]));

    expect($workspace->channels()->where('type', ChannelType::Direct)->count())->toBe(1);
});

it('refuses somebody who does not belong to the workspace', function () {
    [$initiator, , $workspace] = twoMembers();
    $outsider = User::factory()->create();

    actingAs($initiator)
        ->post(route('chat.directs.store', $workspace), ['user_id' => $outsider->id])
        ->assertSessionHasErrors('user_id');

    expect($workspace->channels()->where('type', ChannelType::Direct)->count())->toBe(0);
});

it('needs somebody to write to', function () {
    [$initiator, , $workspace] = twoMembers();

    actingAs($initiator)
        ->post(route('chat.directs.store', $workspace), [])
        ->assertSessionHasErrors('user_id');
});

it('finds candidates by name and by username', function () {
    [$initiator, $recipient, $workspace] = twoMembers();
    $recipient->update(['name' => 'Willemijn de Vries', 'username' => 'willemijn']);

    actingAs($initiator)
        ->getJson(route('chat.directs.candidates', $workspace).'?q=willem')
        ->assertOk()
        ->assertJsonPath('candidates.0.id', $recipient->id);

    actingAs($initiator)
        ->getJson(route('chat.directs.candidates', $workspace).'?q=de vries')
        ->assertOk()
        ->assertJsonPath('candidates.0.id', $recipient->id);
});

it('finds the same person whether or not you type the @', function () {
    [$initiator, $recipient, $workspace] = twoMembers();
    $recipient->update(['name' => 'Fenna Bakker', 'username' => 'fenna']);

    foreach (['fenna', '@fenna'] as $terms) {
        actingAs($initiator)
            ->getJson(route('chat.directs.candidates', $workspace).'?q='.urlencode($terms))
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $recipient->id);
    }
});

it('keeps you out of your own candidate list', function () {
    [$initiator, $recipient, $workspace] = twoMembers();

    actingAs($initiator)
        ->getJson(route('chat.directs.candidates', $workspace))
        ->assertOk()
        ->assertJsonPath('candidates.0.id', $recipient->id)
        ->assertJsonCount(1, 'candidates');
});

it('refuses candidates to somebody outside the workspace', function () {
    [, , $workspace] = twoMembers();
    $outsider = User::factory()->create();

    actingAs($outsider)
        ->getJson(route('chat.directs.candidates', $workspace))
        ->assertForbidden();
});

it('shows a guest only the people from their own channels', function () {
    $guest = User::factory()->create();
    $workspace = workspaceWithMember($guest, SystemRole::Guest);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $stranger = User::factory()->create();
    joinWorkspace($workspace, $stranger, SystemRole::Member);

    $shared = channelWithMember($workspace, $guest);
    $shared->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($guest)
        ->getJson(route('chat.directs.candidates', $workspace))
        ->assertOk()
        ->assertJsonPath('candidates.0.id', $colleague->id)
        ->assertJsonCount(1, 'candidates');
});

it('refuses a guest the person they share no channel with', function () {
    $guest = User::factory()->create();
    $workspace = workspaceWithMember($guest, SystemRole::Guest);
    $stranger = User::factory()->create();
    joinWorkspace($workspace, $stranger, SystemRole::Member);
    channelWithMember($workspace, $guest);

    actingAs($guest)
        ->post(route('chat.directs.store', $workspace), ['user_id' => $stranger->id])
        ->assertForbidden();

    expect($workspace->channels()->where('type', ChannelType::Direct)->count())->toBe(0);
});

it('lets a guest write to somebody from their own channel', function () {
    $guest = User::factory()->create();
    $workspace = workspaceWithMember($guest, SystemRole::Guest);
    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $shared = channelWithMember($workspace, $guest);
    $shared->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($guest)
        ->post(route('chat.directs.store', $workspace), ['user_id' => $colleague->id])
        ->assertRedirect();

    expect($workspace->channels()->where('type', ChannelType::Direct)->count())->toBe(1);
});

it('offers the button to members and guests alike', function () {
    [$initiator, , $workspace] = twoMembers();
    $channel = channelWithMember($workspace, $initiator);

    actingAs($initiator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canStartDirectMessage', true));
});
