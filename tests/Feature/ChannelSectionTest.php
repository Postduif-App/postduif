<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Somebody with two channels to arrange.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function arrangeableChannels(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

it('makes a group', function () {
    [$user, $workspace] = arrangeableChannels();

    actingAs($user)
        ->post(route('chat.sections.store', $workspace), ['name' => 'Klanten'])
        ->assertRedirect();

    expect(ChannelSection::sole())
        ->name->toBe('Klanten')
        ->user_id->toBe($user->id)
        ->workspace_id->toBe($workspace->id);
});

it('refuses a second group with the same name', function () {
    [$user, $workspace] = arrangeableChannels();

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Klanten']);
    actingAs($user)
        ->post(route('chat.sections.store', $workspace), ['name' => 'Klanten'])
        ->assertSessionHasErrors('name');

    expect(ChannelSection::count())->toBe(1);
});

/** Two people may both have a group called "Klanten"; neither is the other's. */
it('lets two members use the same name', function () {
    [$user, $workspace] = arrangeableChannels();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Klanten']);
    actingAs($colleague)
        ->post(route('chat.sections.store', $workspace), ['name' => 'Klanten'])
        ->assertRedirect();

    expect(ChannelSection::count())->toBe(2);
});

it('files a channel into a group and takes it out again', function () {
    [$user, $workspace, $channel] = arrangeableChannels();

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Klanten']);
    $section = ChannelSection::sole();

    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => $section->id,
    ])->assertRedirect();

    expect($section->fresh()->channels->pluck('id')->all())->toBe([$channel->id]);

    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => null,
    ]);

    expect($section->fresh()->channels)->toBeEmpty();
});

/** A channel in two groups is in neither, as far as finding it back goes. */
it('moves a channel rather than copying it', function () {
    [$user, $workspace, $channel] = arrangeableChannels();

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Eerste']);
    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Tweede']);

    [$first, $second] = ChannelSection::inOrder()->get()->all();

    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => $first->id,
    ]);
    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => $second->id,
    ]);

    expect($first->fresh()->channels)->toBeEmpty()
        ->and($second->fresh()->channels->pluck('id')->all())->toBe([$channel->id]);
});

it('does not accept a group belonging to somebody else', function () {
    [$user, $workspace, $channel] = arrangeableChannels();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($colleague)->post(route('chat.sections.store', $workspace), ['name' => 'Van mij']);
    $theirs = ChannelSection::sole();

    actingAs($user)
        ->put(route('chat.sections.update', $workspace), [
            'channel_id' => $channel->id,
            'section_id' => $theirs->id,
        ])
        ->assertSessionHasErrors('section_id');
});

it('takes a group away without taking its channels', function () {
    [$user, $workspace, $channel] = arrangeableChannels();

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Klanten']);
    $section = ChannelSection::sole();

    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => $section->id,
    ]);

    actingAs($user)
        ->delete(route('chat.sections.destroy', [$workspace, $section]))
        ->assertRedirect();

    expect(ChannelSection::count())->toBe(0)
        // The channel is still there, back in the ordinary list.
        ->and($channel->fresh())->not->toBeNull();
});

it('does not let somebody delete another member group', function () {
    [$user, $workspace] = arrangeableChannels();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($colleague)->post(route('chat.sections.store', $workspace), ['name' => 'Van mij']);
    $theirs = ChannelSection::sole();

    actingAs($user)
        ->delete(route('chat.sections.destroy', [$workspace, $theirs]))
        ->assertNotFound();

    expect(ChannelSection::count())->toBe(1);
});

it('sends the groups along with the sidebar', function () {
    [$user, $workspace, $channel] = arrangeableChannels();

    actingAs($user)->post(route('chat.sections.store', $workspace), ['name' => 'Klanten']);
    actingAs($user)->put(route('chat.sections.update', $workspace), [
        'channel_id' => $channel->id,
        'section_id' => ChannelSection::sole()->id,
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sections', 1)
            ->where('sections.0.name', 'Klanten')
            ->where('sections.0.channelIds', [$channel->id]));

    // And a colleague sees none of it.
    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('sections', 0));
});
