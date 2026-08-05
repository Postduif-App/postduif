<?php

use App\Enums\SystemRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\post;

beforeEach(function () {
    Mail::fake();
});

/**
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Channel}
 */
function workspaceToInviteInto(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->forceFill(['owner_id' => $owner->id])->save();

    $invited = Channel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'klantproject']);
    $offLimits = Channel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'intern']);

    return [$owner, $workspace, $invited, $offLimits];
}

function pendingGuestInvitation(Workspace $workspace, User $inviter, Channel ...$channels): Invitation
{
    $invitation = Invitation::factory()->guest()->create([
        'workspace_id' => $workspace->id,
        'invited_by' => $inviter->id,
        'email' => 'gast@extern.nl',
    ]);

    $invitation->channels()->sync(collect($channels)->pluck('id'));

    return $invitation;
}

it('invites a guest for the channels that were picked', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();

    actingAs($owner)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'Gast@Extern.nl',
            'role' => 'guest',
            'channel_ids' => [$invited->id],
        ])
        ->assertRedirect();

    $invitation = Invitation::sole();

    // Stored lowercased: the same person invited twice under a different
    // spelling would otherwise slip past the unique key on (workspace, email).
    expect($invitation->email)->toBe('gast@extern.nl')
        ->and($invitation->role)->toBe(SystemRole::Guest)
        ->and($invitation->workspace_id)->toBe($workspace->id)
        ->and($invitation->channels->pluck('id')->all())->toBe([$invited->id]);

    Mail::assertSent(
        WorkspaceInvitationMail::class,
        fn (WorkspaceInvitationMail $mail): bool => $mail->hasTo('gast@extern.nl'),
    );
});

it('refuses a guest invitation without channels', function () {
    [$owner, $workspace] = workspaceToInviteInto();

    actingAs($owner)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'gast@extern.nl',
            'role' => 'guest',
        ])
        ->assertSessionHasErrors('channel_ids');

    expect(Invitation::count())->toBe(0);
});

it('ignores channels from another workspace', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $elsewhere = Channel::factory()->create();

    actingAs($owner)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'gast@extern.nl',
            'role' => 'guest',
            'channel_ids' => [$invited->id, $elsewhere->id],
        ])
        ->assertRedirect();

    expect(Invitation::sole()->channels->pluck('id')->all())->toBe([$invited->id]);
});

it('never lets an ordinary member invite anybody', function () {
    [, $workspace, $invited] = workspaceToInviteInto();
    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($member)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'gast@extern.nl',
            'role' => 'guest',
            'channel_ids' => [$invited->id],
        ])
        ->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

it('refuses to invite somebody who is already in the workspace', function () {
    [$owner, $workspace] = workspaceToInviteInto();
    $member = User::factory()->create(['email' => 'lid@intern.nl']);
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($owner)
        ->post(route('chat.invitations.store', $workspace), [
            'email' => 'lid@intern.nl',
            'role' => 'member',
        ])
        ->assertSessionHasErrors('email');
});

it('onboards a guest who has no account yet', function () {
    [$owner, $workspace, $invited, $offLimits] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);

    post(route('invitations.accept', $invitation->token), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ])->assertRedirect(route('chat.index', $workspace));

    $guest = User::where('email', 'gast@extern.nl')->sole();

    expect($guest->username)->toBe('renske.de.vries')
        // Reaching the link proves they read mail sent to that address, which
        // is the whole of what a verification mail establishes.
        ->and($guest->email_verified_at)->not->toBeNull()
        ->and($workspace->roleFor($guest))->toBe(SystemRole::Guest)
        ->and($guest->channels->pluck('id')->all())->toBe([$invited->id])
        ->and($guest->channels->contains($offLimits))->toBeFalse();

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('signs the new guest in', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);

    post(route('invitations.accept', $invitation->token), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ]);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe('gast@extern.nl');
});

it('lets somebody who is already signed in accept their own invitation', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $guest = User::factory()->create(['email' => 'gast@extern.nl']);

    actingAs($guest)
        ->post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('chat.index', $workspace));

    expect($workspace->roleFor($guest))->toBe(SystemRole::Guest)
        ->and($guest->channels()->pluck('channels.id')->all())->toBe([$invited->id]);
});

/**
 * An invitation names one address. Accepting it while signed in as somebody
 * else would put the wrong account in the workspace.
 */
it('never accepts an invitation on behalf of another account', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $somebodyElse = User::factory()->create(['email' => 'iemand@anders.nl']);

    actingAs($somebodyElse)
        ->post(route('invitations.accept', $invitation->token))
        ->assertForbidden();

    expect($workspace->hasMember($somebodyElse))->toBeFalse();
});

it('sends an invited address that already has an account through the login screen', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    User::factory()->create(['email' => 'gast@extern.nl']);

    post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('login'));

    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('refuses an expired token', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    post(route('invitations.accept', $invitation->token), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ])->assertStatus(410);

    assertDatabaseMissing('users', ['email' => 'gast@extern.nl']);
});

it('refuses a token that was already used', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $invitation->forceFill(['accepted_at' => now()->subHour()])->save();

    post(route('invitations.accept', $invitation->token), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ])->assertStatus(410);
});

it('refuses a token that means nothing', function () {
    post(route('invitations.accept', 'volstrekt-verzonnen'), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ])->assertStatus(410);
});

it('explains an expired link rather than showing a dead end', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->get(route('invitations.show', $invitation->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/invitation')
            ->where('state', 'expired')
            ->where('mode', 'none'));
});

it('offers the sign-up form to an invited stranger', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);

    $this->get(route('invitations.show', $invitation->token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/invitation')
            ->where('state', 'pending')
            ->where('mode', 'register')
            ->where('invitation.isGuest', true)
            ->where('invitation.channels', ['klantproject']));
});

it('rotates the token when an invitation is sent again', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);
    $oldToken = $invitation->token;

    actingAs($owner)
        ->post(route('chat.invitations.resend', [$workspace, $invitation]))
        ->assertRedirect();

    expect($invitation->fresh()->token)->not->toBe($oldToken)
        ->and($invitation->fresh()->channels->pluck('id')->all())->toBe([$invited->id]);

    Mail::assertSent(WorkspaceInvitationMail::class);

    post(route('invitations.accept', $oldToken), [
        'name' => 'Renske de Vries',
        'password' => 'zeer-geheim-wachtwoord',
        'password_confirmation' => 'zeer-geheim-wachtwoord',
    ])->assertStatus(410);
});

it('withdraws an invitation', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    $invitation = pendingGuestInvitation($workspace, $owner, $invited);

    actingAs($owner)
        ->delete(route('chat.invitations.destroy', [$workspace, $invitation]))
        ->assertRedirect();

    assertDatabaseMissing('invitations', ['id' => $invitation->id]);
    assertDatabaseMissing('channel_invitation', ['invitation_id' => $invitation->id]);
});

it('never lets an invitation from another workspace be withdrawn', function () {
    [$owner, $workspace] = workspaceToInviteInto();
    $elsewhere = Invitation::factory()->create();

    actingAs($owner)
        ->delete(route('chat.invitations.destroy', [$workspace, $elsewhere]))
        ->assertNotFound();

    assertDatabaseHas('invitations', ['id' => $elsewhere->id]);
});

it('lists pending invitations on their own settings screen', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    pendingGuestInvitation($workspace, $owner, $invited);

    actingAs($owner)
        ->get(route('workspace.invitations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/invitations')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'gast@extern.nl')
            ->where('invitations.0.roleLabel', 'Gast')
            ->where('invitations.0.channels', ['klantproject']));
});

/**
 * Those addresses belong to people who are not in the workspace. Somebody who
 * cannot invite has no business reading them.
 */
it('refuses the invitations screen to a plain member', function () {
    [, $workspace] = workspaceToInviteInto();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($member)
        ->get(route('workspace.invitations.index'))
        ->assertForbidden();
});

/**
 * The chat offers the button; the list of who is still out there lives in
 * settings. Sending those addresses to every chat page would be one copy too
 * many, and a copy that everybody in the workspace could read.
 */
it('keeps invitations out of the chat page', function () {
    [$owner, $workspace, $invited] = workspaceToInviteInto();
    pendingGuestInvitation($workspace, $owner, $invited);

    actingAs($owner)
        ->get(route('chat.show', [$workspace, $invited]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.canInvite', true)
            ->missing('invitations'));
});
