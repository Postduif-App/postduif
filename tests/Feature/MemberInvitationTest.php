<?php

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

/**
 * Inviting a full member, as opposed to a guest.
 *
 * The token, the mail and the accept screen are one shared layer — built for
 * guests first and covered in GuestInvitationTest. What is different about a
 * member is what the invitation grants: no channel list, because they find the
 * public channels themselves, and a standing in the workspace that a guest
 * never gets. That difference is what this file is about.
 */
beforeEach(function () {
    Mail::fake();
});

/**
 * A workspace with somebody who may invite, and a public channel nobody was
 * explicitly put in.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function workspaceExpectingAMember(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->forceFill(['owner_id' => $owner->id])->save();

    $openToEveryone = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
        'name' => 'algemeen',
        'slug' => 'algemeen',
    ]);

    return [$owner, $workspace, $openToEveryone];
}

it('invites somebody as a member without naming any channels', function () {
    [$owner, $workspace] = workspaceExpectingAMember();

    actingAs($owner)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'Nieuw@Collega.nl',
            'role' => SystemRole::Member->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Addresses are lowercased on the way in, so the same person cannot be
    // invited twice under two spellings of one mailbox.
    assertDatabaseHas('invitations', [
        'workspace_id' => $workspace->id,
        'email' => 'nieuw@collega.nl',
        'role' => SystemRole::Member->value,
    ]);

    $invitation = Invitation::where('email', 'nieuw@collega.nl')->sole();

    expect($invitation->channels()->count())->toBe(0);

    Mail::assertSent(WorkspaceInvitationMail::class);
});

it('lands an accepted member in the workspace with the public channels open', function () {
    [$owner, $workspace, $openToEveryone] = workspaceExpectingAMember();

    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => 'nieuw@collega.nl',
    ]);

    post(route('invitations.accept', $invitation->token), [
        'name' => 'Nieuwe Collega',
        'password' => 'wachtwoord-van-de-collega',
        'password_confirmation' => 'wachtwoord-van-de-collega',
    ])->assertRedirect(route('chat.index', $workspace));

    $member = User::where('email', 'nieuw@collega.nl')->sole();

    // The channel was never handed to them, but a member may browse the
    // workspace — which is the whole difference with a guest.
    expect($workspace->roleFor($member)?->key)->toBe(SystemRole::Member->value)
        ->and($workspace->channels()->visibleTo($member)->pluck('id')->all())
        ->toBe([$openToEveryone->id])
        ->and($openToEveryone->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('lets an admin invite a member', function () {
    [, $workspace] = workspaceExpectingAMember();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($admin)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'nieuw@collega.nl',
            'role' => SystemRole::Member->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    assertDatabaseHas('invitations', ['email' => 'nieuw@collega.nl']);
});

/**
 * An admin who could invite an owner could appoint themselves one by mailing
 * their own address — so handing out ownership stays the owner's alone, the
 * same rule the member list follows.
 */
it('never lets an admin invite somebody as owner', function () {
    [, $workspace] = workspaceExpectingAMember();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($admin)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'nieuw@collega.nl',
            'role' => SystemRole::Owner->value,
        ])
        ->assertForbidden();

    expect(Invitation::where('email', 'nieuw@collega.nl')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('keeps an existing member at the standing they already have', function () {
    [$owner, $workspace] = workspaceExpectingAMember();

    $admin = User::factory()->create(['email' => 'beheerder@intern.nl']);
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    // Not through the controller: it refuses an address that is already in the
    // workspace. This is the row that would be left behind if somebody was
    // invited and joined in the meantime.
    $invitation = Invitation::factory()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $owner->id,
        'email' => $admin->email,
        'role' => SystemRole::Member,
    ]);

    actingAs($admin)
        ->post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('chat.index', $workspace));

    expect($workspace->roleFor($admin)?->key)->toBe(SystemRole::Admin->value);
});
