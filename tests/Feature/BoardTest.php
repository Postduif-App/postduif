<?php

use App\Enums\SystemRole;
use App\Features\MessageBoard;
use App\Models\BoardPost;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A workspace with a prikbord, somebody who may read it, and a guest who may
 * not.
 *
 * The channel comes along because the rail is only ever drawn on a real chat
 * screen: chat.index redirects to whichever channel was busiest, so a test that
 * wants to look at the sidebar has to have somewhere for it to land.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function boardFixture(): array
{
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);
    $channel->members()->attach([$member->id, $guest->id], ['joined_at' => now()]);

    return [$member, $guest, $workspace, $channel];
}

it('lists what is on the board', function () {
    [$member, , $workspace] = boardFixture();

    BoardPost::factory()->in($workspace)->create(['title' => 'Kerstborrel']);
    BoardPost::factory()->in($workspace)->create(['title' => 'Nieuwe koffiemachine']);

    actingAs($member)
        ->get(route('chat.board.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->component('chat/board')
            ->has('posts', 2)
        );
});

/**
 * The rule the whole feature is built around, stated at the door rather than
 * per notice.
 */
it('keeps a guest off the board altogether', function () {
    [, $guest, $workspace] = boardFixture();

    BoardPost::factory()->in($workspace)->create();

    actingAs($guest)
        ->get(route('chat.board.index', $workspace))
        ->assertForbidden();
});

it('hides the rail entry from a guest and shows it to a member', function () {
    [$member, $guest, $workspace, $channel] = boardFixture();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.board', true));

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.board', false));
});

it('closes the board when the workspace switches the feature off', function () {
    [$member, , $workspace, $channel] = boardFixture();

    Feature::for($workspace)->deactivate(MessageBoard::class);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.board', false));

    /*
     * 404 rather than 403, which is EnsureFeatureIsActive's rule and worth
     * asserting here: "not you" would tell a member the board exists and they
     * are being kept off it. A feature that is switched off is not there.
     */
    actingAs($member)
        ->get(route('chat.board.index', $workspace))
        ->assertNotFound();
});

/** A notice belongs to one workspace and is not readable from another. */
it('does not open a notice from another workspace', function () {
    [$member, , $workspace] = boardFixture();

    $elsewhere = workspaceWithMember(User::factory()->create());
    $secret = BoardPost::factory()->in($elsewhere)->create(['title' => 'Geheim']);

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $secret->id]))
        ->assertInertia(fn ($page) => $page->where('post', null));
});

/**
 * Hoe een mededeling openstaat reist mee in de URL, naast welke.
 *
 * Dat is het hele punt van een querysleutel in plaats van componenttoestand: de
 * lange mededeling — het jaarplan, de huisregels — is precies het soort dat
 * iemand doorstuurt, en een link die opengevouwen verstuurd is moet opengevouwen
 * aankomen.
 */
it('opens a notice on the whole screen when the link says so', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id, 'full' => 1]))
        ->assertInertia(fn ($page) => $page->where('fullscreen', true));

    actingAs($member)
        ->get(route('chat.board.index', [$workspace, 'post' => $post->id]))
        ->assertInertia(fn ($page) => $page->where('fullscreen', false));
});

/** Antwoorden mag je niet uit het volle scherm terugduwen. */
it('keeps the notice full screen after a reply', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->create();

    actingAs($member)
        ->post(route('chat.board.comments.store', [$workspace, $post]), [
            'body' => 'Genoteerd.',
            'full' => 1,
        ])
        ->assertRedirect(route('chat.board.index', [$workspace, 'post' => $post->id, 'full' => 1]));
});

it('puts a notice up and opens it', function () {
    [$member, , $workspace] = boardFixture();

    $response = actingAs($member)->post(route('chat.board.store', $workspace), [
        'title' => 'Verhuizing naar de tweede verdieping',
        'body' => 'Vanaf maandag zitten we boven.',
    ]);

    $post = BoardPost::query()->sole();

    expect($post->title)->toBe('Verhuizing naar de tweede verdieping')
        ->and($post->user_id)->toBe($member->id);

    $response->assertRedirect(route('chat.board.index', [$workspace, 'post' => $post->id]));
});

it('refuses a guest who tries to post anyway', function () {
    [, $guest, $workspace] = boardFixture();

    actingAs($guest)
        ->post(route('chat.board.store', $workspace), [
            'title' => 'Hallo',
            'body' => 'Iemand?',
        ])
        ->assertForbidden();

    expect(BoardPost::query()->count())->toBe(0);
});

it('marks a corrected notice as corrected', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->by($member)->create();

    actingAs($member)->patch(route('chat.board.update', [$workspace, $post]), [
        'title' => 'Verhuizing gaat niet door',
        'body' => 'We blijven toch beneden.',
    ]);

    $post->refresh();

    expect($post->title)->toBe('Verhuizing gaat niet door')
        ->and($post->edited_at)->not->toBeNull();
});

it('lets nobody but the author or a beheerder correct a notice', function () {
    [$member, , $workspace] = boardFixture();

    $other = User::factory()->create();
    $workspace->members()->attach($other->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    $post = BoardPost::factory()->in($workspace)->by($member)->create(['title' => 'Van mij']);

    actingAs($other)
        ->patch(route('chat.board.update', [$workspace, $post]), [
            'title' => 'Van jou',
            'body' => 'Zomaar.',
        ])
        ->assertForbidden();

    expect($post->refresh()->title)->toBe('Van mij');
});

/**
 * Pinning is narrower than posting: it is a claim on everybody else's
 * attention, not on your own notice.
 */
it('lets only a beheerder pin a notice', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->by($member)->create();

    actingAs($member)
        ->patch(route('chat.board.update', [$workspace, $post]), ['pinned' => true])
        ->assertForbidden();

    expect($post->refresh()->pinned_at)->toBeNull();

    $beheerder = User::factory()->create();
    $workspace->members()->attach($beheerder->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($beheerder)
        ->patch(route('chat.board.update', [$workspace, $post]), ['pinned' => true]);

    expect($post->refresh()->pinned_at)->not->toBeNull();
});

it('puts pinned notices at the top, newest first underneath', function () {
    [$member, , $workspace] = boardFixture();

    BoardPost::factory()->in($workspace)->create([
        'title' => 'Oud',
        'created_at' => now()->subWeek(),
    ]);
    BoardPost::factory()->in($workspace)->create([
        'title' => 'Nieuw',
        'created_at' => now(),
    ]);
    BoardPost::factory()->in($workspace)->pinned()->create([
        'title' => 'Vastgezet',
        'created_at' => now()->subMonth(),
    ]);

    actingAs($member)
        ->get(route('chat.board.index', $workspace))
        ->assertInertia(fn ($page) => $page
            ->where('posts.0.title', 'Vastgezet')
            ->where('posts.1.title', 'Nieuw')
            ->where('posts.2.title', 'Oud')
        );
});

it('takes a notice down without losing the row', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->by($member)->create();

    actingAs($member)
        ->delete(route('chat.board.destroy', [$workspace, $post]))
        ->assertRedirect(route('chat.board.index', $workspace));

    expect(BoardPost::query()->count())->toBe(0)
        ->and(BoardPost::withTrashed()->count())->toBe(1);
});

/**
 * The notice outlives the person who wrote it: a board that empties itself when
 * somebody offboards is one nobody dares rely on.
 */
it('keeps a notice after its author leaves', function () {
    [$member, , $workspace] = boardFixture();

    $post = BoardPost::factory()->in($workspace)->by($member)->create();

    $member->delete();

    expect($post->refresh()->user_id)->toBeNull();
});

it('masks the workspace blocklist on the board', function () {
    [$member, , $workspace] = boardFixture();

    $workspace->update(['blocked_words' => ['geheim']]);

    BoardPost::factory()->in($workspace)->create([
        'title' => 'Iets geheim',
        'body' => 'Dit is geheim.',
    ]);

    actingAs($member)
        ->get(route('chat.board.index', $workspace))
        ->assertInertia(fn ($page) => $page->where('posts.0.title', 'Iets ******'));
});
