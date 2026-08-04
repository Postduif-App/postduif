<?php

use App\Models\ChannelSection;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;

/**
 * A member with a group of their own.
 *
 * @return array{0: User, 1: Workspace, 2: ChannelSection}
 */
function sectionFixture(string $name = 'Klanten'): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $section = ChannelSection::create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => $name,
        'position' => 0,
    ]);

    return [$user, $workspace, $section];
}

it('gives a group a different name', function () {
    [$user, $workspace, $section] = sectionFixture();

    actingAs($user);

    patch(route('chat.sections.rename', [$workspace, $section]), ['name' => 'Leveranciers'])
        ->assertRedirect();

    expect($section->fresh()->name)->toBe('Leveranciers');
});

it('lets a group keep the name it already has', function () {
    [$user, $workspace, $section] = sectionFixture();

    actingAs($user);

    // Saving without changing anything is the ordinary case of opening the
    // field and thinking better of it; the uniqueness rule must not read that
    // as a collision with itself.
    patch(route('chat.sections.rename', [$workspace, $section]), ['name' => 'Klanten'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('refuses a name this member already used', function () {
    [$user, $workspace, $section] = sectionFixture();

    ChannelSection::create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'name' => 'Leveranciers',
        'position' => 1,
    ]);

    actingAs($user);

    patch(route('chat.sections.rename', [$workspace, $section]), ['name' => 'Leveranciers'])
        ->assertSessionHasErrors('name');

    expect($section->fresh()->name)->toBe('Klanten');
});

it('lets two people both have a group called the same thing', function () {
    [, $workspace, $section] = sectionFixture();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    $theirs = ChannelSection::create([
        'user_id' => $colleague->id,
        'workspace_id' => $workspace->id,
        'name' => 'Leveranciers',
        'position' => 0,
    ]);

    // A section is one member's way of arranging their own sidebar, so the
    // names live in separate worlds.
    actingAs($colleague);

    patch(route('chat.sections.rename', [$workspace, $theirs]), ['name' => 'Klanten'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($theirs->fresh()->name)->toBe('Klanten')
        ->and($section->fresh()->name)->toBe('Klanten');
});

it('refuses to rename somebody else group', function () {
    [, $workspace, $section] = sectionFixture();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($colleague);

    // A 404 rather than a 403: as far as this member is concerned somebody
    // else's section does not exist, which is also what the move endpoint says.
    patch(route('chat.sections.rename', [$workspace, $section]), ['name' => 'Van mij nu'])
        ->assertNotFound();

    expect($section->fresh()->name)->toBe('Klanten');
});

it('refuses an empty name', function () {
    [$user, $workspace, $section] = sectionFixture();

    actingAs($user);

    patch(route('chat.sections.rename', [$workspace, $section]), ['name' => '  '])
        ->assertSessionHasErrors('name');
});
