<?php

use App\Models\BoardPost;
use App\Models\BoardPostReaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Emoji onder een mededeling.
 *
 * Deelt boardFixture() met BoardTest: één workspace, iemand die het prikbord mag
 * lezen en een gast die dat niet mag. Die gast is hier de interessantste — de
 * hele feature is gebouwd rond de regel dat hij het bord niet ziet, en een
 * emoji-route is precies het soort achterdeur waar zo'n regel doorheen lekt.
 */
it('puts an emoji under a notice', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)
        ->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '👍'])
        ->assertRedirect();

    expect($post->reactions()->count())->toBe(1)
        ->and($post->reactions()->sole()->user_id)->toBe($member->id);
});

/** Eén route voor beide richtingen: nog een keer klikken haalt hem weg. */
it('takes the same emoji off again', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '👍']);
    actingAs($member)->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '👍']);

    expect($post->reactions()->count())->toBe(0);
});

/** Twee verschillende emoji van dezelfde persoon staan naast elkaar. */
it('keeps one person\'s different emoji apart', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '👍']);
    actingAs($member)->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '🎉']);

    expect($post->reactions()->count())->toBe(2);
});

it('keeps a guest from reacting', function () {
    [, $guest, $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($guest)
        ->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => '👍'])
        ->assertForbidden();

    expect($post->reactions()->count())->toBe(0);
});

/** Een pil is een symbool, geen label — anders staat er "lgtm" onder. */
it('refuses something that is not an emoji', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)
        ->post(route('chat.board.reactions.store', [$workspace, $post]), ['emoji' => 'lgtm'])
        ->assertSessionHasErrors('emoji');

    expect($post->reactions()->count())->toBe(0);
});

/** Een mededeling van een andere workspace is een 404, geen 403. */
it('does not react to a notice from another workspace', function () {
    [$member, , $workspace] = boardFixture();

    $elsewhere = workspaceWithMember(User::factory()->create());
    $secret = BoardPost::factory()->in($elsewhere)->create();

    actingAs($member)
        ->post(route('chat.board.reactions.store', [$workspace, $secret]), ['emoji' => '👍'])
        ->assertNotFound();
});

it('sends the emoji along with the open notice, grouped', function () {
    [$member, , $workspace] = boardFixture();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    $post = BoardPost::factory()->in($workspace)->create();

    foreach ([$member, $colleague] as $person) {
        BoardPostReaction::create([
            'board_post_id' => $post->id,
            'user_id' => $person->id,
            'emoji' => '👍',
        ]);
    }

    BoardPostReaction::create([
        'board_post_id' => $post->id,
        'user_id' => $colleague->id,
        'emoji' => '🎉',
    ]);

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id]))
        ->assertInertia(fn ($page) => $page
            // Twee rijen voor drie reacties: gegroepeerd per emoji, en de
            // drukste bovenaan.
            ->has('post.reactions', 2)
            ->where('post.reactions.0.emoji', '👍')
            ->where('post.reactions.0.count', 2)
            ->where('post.reactions.0.mine', true)
            ->where('post.reactions.1.emoji', '🎉')
            ->where('post.reactions.1.mine', false)
        );
});

/**
 * Een reactie overleeft het account erachter niet.
 *
 * Anders dan de mededeling zelf, die blijft hangen met "Oud-collega" eronder:
 * die is iets wat gezegd is en blijft gelden, een emoji is een instemming van
 * iemand die er niet meer is. Dit legt die keuze vast, want hij zit in het
 * cascade-gedrag van de tabel en nergens anders.
 */
it('lets a reaction go with the account behind it', function () {
    [$member, , $workspace] = boardFixture();

    $leaver = User::factory()->create();
    $post = BoardPost::factory()->in($workspace)->create();

    BoardPostReaction::create([
        'board_post_id' => $post->id,
        'user_id' => $leaver->id,
        'emoji' => '👍',
    ]);

    $leaver->delete();

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id]))
        ->assertInertia(fn ($page) => $page->has('post.reactions', 0));
});
