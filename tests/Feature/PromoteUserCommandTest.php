<?php

use App\Models\User;
use Filament\Facades\Filament;

test('it makes a user a platform moderator', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);

    $this->artisan('user:promote', ['email' => 'sam@example.test'])
        ->expectsConfirmation('Doorgaan?', 'yes')
        ->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeTrue();
});

test('it leaves the user alone when the confirmation is declined', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);

    $this->artisan('user:promote', ['email' => 'sam@example.test'])
        ->expectsConfirmation('Doorgaan?', 'no')
        ->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeFalse();
});

test('it fails when no user has that address', function () {
    $this->artisan('user:promote', ['email' => 'niemand@example.test'])
        ->assertFailed();
});

test('it says nothing to do when the user is already a moderator', function () {
    $user = User::factory()->admin()->create(['email' => 'sam@example.test']);
    $promotedAt = $user->admin_at;

    $this->artisan('user:promote', ['email' => 'sam@example.test'])
        ->assertSuccessful();

    expect($user->refresh()->admin_at->equalTo($promotedAt))->toBeTrue();
});

test('it warns that a suspended moderator still cannot reach the panel', function () {
    $user = User::factory()->suspended()->create(['email' => 'sam@example.test']);

    $this->artisan('user:promote', ['email' => 'sam@example.test'])
        ->expectsConfirmation('Doorgaan?', 'yes')
        ->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeTrue()
        ->and($user->canAccessPanel(Filament::getDefaultPanel()))->toBeFalse();
});

test('it revokes moderation rights', function () {
    $user = User::factory()->admin()->create(['email' => 'sam@example.test']);

    $this->artisan('user:promote', ['email' => 'sam@example.test', '--revoke' => true])
        ->expectsConfirmation('Doorgaan?', 'yes')
        ->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeFalse();
});

test('it does not revoke rights the user never had', function () {
    $user = User::factory()->create(['email' => 'sam@example.test']);

    $this->artisan('user:promote', ['email' => 'sam@example.test', '--revoke' => true])
        ->assertSuccessful();

    expect($user->refresh()->isAdmin())->toBeFalse();
});
