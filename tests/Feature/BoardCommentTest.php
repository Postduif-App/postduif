<?php

use App\Enums\SystemRole;
use App\Models\BoardComment;
use App\Models\BoardPost;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets a member reply to a notice', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)
        ->post(route('chat.board.comments.store', [$workspace, $post]), [
            'body' => 'Ik neem taart mee.',
        ])
        ->assertRedirect(route('chat.board.index', [$workspace, 'post' => $post->id]));

    $comment = BoardComment::query()->sole();

    expect($comment->body)->toBe('Ik neem taart mee.')
        ->and($comment->user_id)->toBe($member->id);
});

it('refuses a guest who tries to reply', function () {
    [, $guest, $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($guest)
        ->post(route('chat.board.comments.store', [$workspace, $post]), [
            'body' => 'Mag ik ook?',
        ])
        ->assertForbidden();

    expect(BoardComment::query()->count())->toBe(0);
});

it('draws the replies under the notice it opens', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();
    BoardComment::factory()->on($post)->by($member)->create(['body' => 'Leuk!']);

    // A reply under another notice must not travel along with this one.
    $other = BoardPost::factory()->in($workspace)->create();
    BoardComment::factory()->on($other)->by($member)->create();

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id]))
        ->assertInertia(fn ($page) => $page
            ->has('post.comments', 1)
            ->where('post.comments.0.body', 'Leuk!')
        );
});

it('marks a rewritten reply as rewritten', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($post)->by($member)->create();

    actingAs($member)->patch(
        route('chat.board.comments.update', [$workspace, $post, $comment]),
        ['body' => 'Toch geen taart.'],
    );

    $comment->refresh();

    expect($comment->body)->toBe('Toch geen taart.')
        ->and($comment->edited_at)->not->toBeNull();
});

/**
 * The line every comment in this application draws: withdrawal is visible as
 * withdrawal, an edit is not — so nobody puts words in somebody else's mouth,
 * beheerder or otherwise.
 */
it('lets nobody rewrite another persons reply', function () {
    [$member, , $workspace] = boardFixture();

    $beheerder = User::factory()->create();
    joinWorkspace($workspace, $beheerder, SystemRole::Admin);

    $post = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($post)->by($member)->create(['body' => 'Van mij']);

    actingAs($beheerder)
        ->patch(
            route('chat.board.comments.update', [$workspace, $post, $comment]),
            ['body' => 'Van jou'],
        )
        ->assertForbidden();

    expect($comment->refresh()->body)->toBe('Van mij');
});

it('lets a beheerder take somebody elses reply off the board', function () {
    [$member, , $workspace] = boardFixture();

    $beheerder = User::factory()->create();
    joinWorkspace($workspace, $beheerder, SystemRole::Admin);

    $post = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($post)->by($member)->create();

    actingAs($beheerder)->delete(
        route('chat.board.comments.destroy', [$workspace, $post, $comment]),
    );

    expect(BoardComment::query()->count())->toBe(0)
        ->and(BoardComment::withTrashed()->count())->toBe(1);
});

it('lets an ordinary member withdraw only their own reply', function () {
    [$member, , $workspace] = boardFixture();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);

    $post = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($post)->by($member)->create();

    actingAs($other)
        ->delete(route('chat.board.comments.destroy', [$workspace, $post, $comment]))
        ->assertForbidden();

    expect(BoardComment::query()->count())->toBe(1);
});

/** A withdrawn reply leaves no tombstone: the board is not a support history. */
it('leaves nothing behind when a reply is withdrawn', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($post)->by($member)->create();

    actingAs($member)->delete(
        route('chat.board.comments.destroy', [$workspace, $post, $comment]),
    );

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id]))
        ->assertInertia(fn ($page) => $page->has('post.comments', 0));
});

/** A reply id from another notice is a 404, settled by the route binding. */
it('does not reach a reply through the wrong notice', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();
    $elsewhere = BoardPost::factory()->in($workspace)->create();
    $comment = BoardComment::factory()->on($elsewhere)->by($member)->create();

    actingAs($member)
        ->delete(route('chat.board.comments.destroy', [$workspace, $post, $comment]))
        ->assertNotFound();
});
