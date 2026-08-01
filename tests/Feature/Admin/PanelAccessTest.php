<?php

use App\Models\User;

test('it sends a guest to the panel login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('it keeps an ordinary user out of the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

test('it lets a platform moderator into the panel', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertSuccessful();
});

test('it does not consider an ordinary user an admin', function () {
    expect(User::factory()->create()->isAdmin())->toBeFalse()
        ->and(User::factory()->admin()->create()->isAdmin())->toBeTrue();
});
