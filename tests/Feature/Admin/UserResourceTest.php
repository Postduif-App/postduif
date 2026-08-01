<?php

use App\Filament\Resources\Users\Actions\ToggleAdminAction;
use App\Filament\Resources\Users\Actions\ToggleSuspendedAction;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Message;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('it searches users by username', function () {
    $wanted = User::factory()->create(['username' => 'fenna.devries']);
    $other = User::factory()->create(['username' => 'iemand.anders']);

    Livewire::test(ListUsers::class)
        ->searchTable('fenna.devries')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

test('it filters down to moderators only', function () {
    $moderator = User::factory()->admin()->create();
    $ordinary = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('admin_at')
        ->assertCanSeeTableRecords([$moderator, $this->admin])
        ->assertCanNotSeeTableRecords([$ordinary]);
});

test('it makes someone a platform moderator', function () {
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(ToggleAdminAction::class)->table($user));

    expect($user->refresh()->isAdmin())->toBeTrue();
});

test('it revokes moderation rights again', function () {
    $moderator = User::factory()->admin()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(ToggleAdminAction::class)->table($moderator));

    expect($moderator->refresh()->isAdmin())->toBeFalse();
});

test('it will not let a moderator revoke their own rights', function () {
    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make(ToggleAdminAction::class)->table($this->admin));

    expect($this->admin->refresh()->isAdmin())->toBeTrue();
});

test('it suspends a user', function () {
    $user = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(ToggleSuspendedAction::class)->table($user));

    expect($user->refresh()->isSuspended())->toBeTrue();
});

test('it lifts a suspension again', function () {
    $user = User::factory()->suspended()->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(ToggleSuspendedAction::class)->table($user));

    expect($user->refresh()->isSuspended())->toBeFalse();
});

/**
 * Suspending yourself would take the panel with it, and the panel holds the only
 * way back.
 */
test('it will not let a moderator suspend themselves', function () {
    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make(ToggleSuspendedAction::class)->table($this->admin));

    expect($this->admin->refresh()->isSuspended())->toBeFalse();
});

test('it filters down to suspended users only', function () {
    $suspended = User::factory()->suspended()->create();
    $ordinary = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->filterTable('suspended')
        ->assertCanSeeTableRecords([$suspended])
        ->assertCanNotSeeTableRecords([$ordinary, $this->admin]);
});

/**
 * A suspension must not cost anyone their history: that is the whole reason it
 * exists next to a delete that refuses.
 */
test('it keeps messages and memberships when suspending', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    Message::factory()->create(['channel_id' => $channel->id, 'user_id' => $user->id]);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make(ToggleSuspendedAction::class)->table($user));

    expect($user->refresh()->messages()->count())->toBe(1)
        ->and($user->workspaces()->count())->toBe(1);
});

test('it updates a users handle', function () {
    $user = User::factory()->create(['username' => 'oud.handle']);

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['username' => 'nieuw.handle'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->username)->toBe('nieuw.handle');
});

test('it refuses a handle that is already taken', function () {
    User::factory()->create(['username' => 'bezet']);
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['username' => 'bezet'])
        ->call('save')
        ->assertHasFormErrors(['username']);
});

test('it does not offer a way to create users', function () {
    expect(array_keys(UserResource::getPages()))
        ->not->toContain('create');
});

test('it keeps an ordinary user out of the user list', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();
});

test('it refuses a handle that addresses a whole group', function () {
    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['username' => 'channel'])
        ->call('save')
        ->assertHasFormErrors(['username']);
});
