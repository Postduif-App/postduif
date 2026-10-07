<?php

use App\Models\Channel;
use App\Models\ChannelSection;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

it('files a new channel straight into one of your own groups', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $section = ChannelSection::factory()->for($user)->for($workspace)->create();

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
            'section_id' => $section->id,
        ])
        ->assertRedirect();

    expect($section->channels()->pluck('channels.id')->all())
        ->toBe([Channel::firstWhere('slug', 'marketing')->id]);
});

it('leaves a new channel out of every group when none is picked', function (?string $sectionId) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $section = ChannelSection::factory()->for($user)->for($workspace)->create();

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
            'section_id' => $sectionId,
        ])
        ->assertRedirect();

    expect(Channel::firstWhere('slug', 'marketing'))->not->toBeNull()
        ->and($section->channels()->count())->toBe(0);
})->with([
    'niet meegestuurd' => [null],
    'leeg veld' => [''],
]);

/** A group is yours alone, so a colleague's id must not resolve. */
it('refuses a group that belongs to somebody else', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague);
    $theirs = ChannelSection::factory()->for($colleague)->for($workspace)->create();

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
            'section_id' => $theirs->id,
        ])
        ->assertSessionHasErrors('section_id');

    expect(Channel::firstWhere('slug', 'marketing'))->toBeNull()
        ->and($theirs->channels()->count())->toBe(0);
});

it('refuses your own group from another workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $elsewhere = ChannelSection::factory()
        ->for($user)
        ->for(Workspace::factory())
        ->create();

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
            'section_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('section_id');

    expect(Channel::firstWhere('slug', 'marketing'))->toBeNull();
});
