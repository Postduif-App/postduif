<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

it('stores a face, squared and shrunk', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('avatar.store'), [
            'avatar' => UploadedFile::fake()->image('foto.jpg', 900, 600),
        ])
        ->assertRedirect();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($user->avatar_path))->toBeTrue();

    // Cropped to a square on the way in: what is stored is what is shown, so
    // the four-megabyte original never has to travel.
    $image = getimagesizefromstring((string) Storage::disk('local')->get($user->avatar_path));

    expect($image[0])->toBe($image[1]);
});

it('replaces the previous picture rather than piling up', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('avatar.store'), [
        'avatar' => UploadedFile::fake()->image('een.jpg'),
    ]);

    $first = $user->fresh()->avatar_path;

    actingAs($user)->post(route('avatar.store'), [
        'avatar' => UploadedFile::fake()->image('twee.jpg'),
    ]);

    $second = $user->fresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse();
});

it('takes the face away, file and all', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('avatar.store'), [
        'avatar' => UploadedFile::fake()->image('foto.jpg'),
    ]);

    $path = $user->fresh()->avatar_path;

    actingAs($user)->delete(route('avatar.destroy'))->assertRedirect();

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('refuses something that is not a picture', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('avatar.store'), [
            'avatar' => UploadedFile::fake()->create('notulen.pdf', 20, 'application/pdf'),
        ])
        ->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('hands the picture to somebody who shares a workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)->post(route('avatar.store'), [
        'avatar' => UploadedFile::fake()->image('foto.jpg'),
    ]);

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($colleague)
        ->get(route('avatars.user', $user))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');
});

/**
 * This application can hold several organisations, and somebody in one has no
 * business with the faces in another.
 */
it('refuses somebody who shares no workspace', function () {
    $user = User::factory()->create();
    workspaceWithMember($user);

    actingAs($user)->post(route('avatar.store'), [
        'avatar' => UploadedFile::fake()->image('foto.jpg'),
    ]);

    actingAs(User::factory()->create())
        ->get(route('avatars.user', $user))
        ->assertNotFound();
});

it('answers with nothing for somebody who set no picture', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($colleague)->get(route('avatars.user', $user))->assertNotFound();
});

it('is not reachable while signed out', function () {
    $user = User::factory()->create();

    $this->get(route('avatars.user', $user))->assertRedirect(route('login'));
});
