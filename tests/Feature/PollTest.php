<?php

use App\Actions\Polls\SettlePolls;
use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Events\PollClosed;
use App\Features\Polls;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/** @return array<string, mixed> */
function pollPayload(array $overrides = []): array
{
    return [
        'question' => 'Wanneer doen we de retro?',
        'options' => ['Dinsdag', 'Woensdag'],
        ...$overrides,
    ];
}

it('is part of the product rather than something to switch on', function () {
    expect(Workspace::factory()->create()->hasFeature(Polls::class))->toBeTrue();
});

it('puts a question to the channel', function () {
    [$user, $workspace, $channel] = pollChannel();

    actingAs($user)
        ->post(route('chat.polls.store', [$workspace, $channel]), pollPayload())
        ->assertRedirect();

    $poll = Poll::sole();

    expect($poll)
        ->channel_id->toBe($channel->id)
        ->created_by->toBe($user->id)
        ->question->toBe('Wanneer doen we de retro?')
        ->allows_multiple->toBeFalse();

    expect($poll->options->pluck('label')->all())->toBe(['Dinsdag', 'Woensdag']);

    // It lands as an ordinary message, as a transfer and a secret request do.
    expect(Message::sole()->body)
        ->toContain(route('chat.polls.show', [$workspace->slug, $poll->id]));
});

it('refuses a poll with one answer, which is not a question', function () {
    [$user, $workspace, $channel] = pollChannel();

    actingAs($user)
        ->post(route('chat.polls.store', [$workspace, $channel]), pollPayload([
            'options' => ['Dinsdag'],
        ]))
        ->assertSessionHasErrors('options');

    expect(Poll::count())->toBe(0);
});

it('asks a repeated answer only once', function () {
    [$user, $workspace, $channel] = pollChannel();

    actingAs($user)
        ->post(route('chat.polls.store', [$workspace, $channel]), pollPayload([
            'options' => ['Dinsdag', 'Woensdag', 'Dinsdag'],
        ]))
        ->assertRedirect();

    expect(Poll::sole()->options)->toHaveCount(2);
});

it('does not let somebody ask in a channel they may not post in', function () {
    [$user, $workspace] = pollChannel();

    $closed = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'posting_policy' => ChannelPostingPolicy::Admins,
    ]);
    $closed->members()->attach($user->id, ['joined_at' => now()]);

    actingAs($user)
        ->post(route('chat.polls.store', [$workspace, $closed]), pollPayload())
        ->assertForbidden();

    expect(Poll::count())->toBe(0);
});

it('does not exist where the workspace switched polls off', function () {
    [$user, $workspace, $channel] = pollChannel();

    Feature::for($workspace)->deactivate(Polls::class);

    actingAs($user)
        ->post(route('chat.polls.store', [$workspace, $channel]), pollPayload())
        ->assertNotFound();
});

/** A guest is in the channel, and the question is as much about them. */
it('lets a guest ask and answer', function () {
    [, $workspace, $channel] = pollChannel();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($guest)
        ->post(route('chat.polls.store', [$workspace, $channel]), pollPayload())
        ->assertRedirect();

    expect(Poll::count())->toBe(1);
});

it('takes a vote', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);

    actingAs($user)
        ->post(route('chat.polls.vote', [$workspace, $poll, $option]))
        ->assertRedirect();

    expect(PollVote::sole())
        ->poll_option_id->toBe($option->id)
        ->user_id->toBe($user->id);
});

/** The way back is the same gesture as the way in, as with a reaction. */
it('takes the vote off when the same answer is clicked again', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);

    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $option]));
    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $option]));

    expect(PollVote::count())->toBe(0);
});

/**
 * Changing your mind is the ordinary case; refusing it and telling somebody to
 * untick first would be the machine's problem dressed up as theirs.
 */
it('replaces the old vote on a single-choice poll', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $dinsdag = PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Dinsdag']);
    $woensdag = PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Woensdag']);

    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $dinsdag]));
    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $woensdag]));

    expect(PollVote::count())->toBe(1)
        ->and(PollVote::sole()->poll_option_id)->toBe($woensdag->id);
});

it('keeps both ticks on a multiple-choice poll', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->multipleChoice()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $dinsdag = PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Dinsdag']);
    $woensdag = PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Woensdag']);

    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $dinsdag]));
    actingAs($user)->post(route('chat.polls.vote', [$workspace, $poll, $woensdag]));

    expect(PollVote::count())->toBe(2)
        ->and($poll->voterCount())->toBe(1);
});

it('takes nothing once the poll is closed', function (array $state) {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        ...$state,
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);

    actingAs($user)
        ->post(route('chat.polls.vote', [$workspace, $poll, $option]))
        ->assertForbidden();

    expect(PollVote::count())->toBe(0);
})->with([
    'stopped by hand' => [['closed_at' => now()->subHour()]],
    'moment passed' => [['closes_at' => now()->subHour()]],
]);

it('does not let somebody outside the channel vote', function () {
    [, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);

    actingAs(User::factory()->create())
        ->post(route('chat.polls.vote', [$workspace, $poll, $option]))
        ->assertForbidden();
});

/** An option from another poll must not resolve through this one. */
it('refuses an answer that belongs to a different poll', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);
    $stranger = PollOption::factory()->create();

    actingAs($user)
        ->post(route('chat.polls.vote', [$workspace, $poll, $stranger]))
        ->assertNotFound();
});

it('lets the asker close it, and says it was stopped rather than expired', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('chat.polls.close', [$workspace, $poll]))
        ->assertRedirect();

    expect($poll->refresh())
        ->closed_at->not->toBeNull()
        ->isClosed()->toBeTrue();
});

it('does not let a bystander close somebody else poll', function () {
    [$asker, $workspace, $channel] = pollChannel();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $asker->id,
    ]);

    actingAs($other)
        ->delete(route('chat.polls.close', [$workspace, $poll]))
        ->assertForbidden();
});

it('lets the asker open it again, and takes votes once more', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closed_at' => now()->subHour(),
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);

    actingAs($user)
        ->post(route('chat.polls.reopen', [$workspace, $poll]))
        ->assertRedirect();

    expect($poll->refresh())
        ->closed_at->toBeNull()
        ->isClosed()->toBeFalse();

    actingAs($user)
        ->post(route('chat.polls.vote', [$workspace, $poll, $option]))
        ->assertRedirect();

    expect(PollVote::where('poll_option_id', $option->id)->count())->toBe(1);
});

/** Otherwise it would shut again the instant it opened. */
it('drops a deadline that has already passed when reopening', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->subHour(),
    ]);

    actingAs($user)->post(route('chat.polls.reopen', [$workspace, $poll]));

    expect($poll->refresh())
        ->closes_at->toBeNull()
        ->isClosed()->toBeFalse();
});

/** A deadline still ahead of us has shut nothing yet, so it stays. */
it('keeps a deadline that has not passed', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->addDay(),
        'closed_at' => now(),
    ]);

    actingAs($user)->post(route('chat.polls.reopen', [$workspace, $poll]));

    expect($poll->refresh())
        ->closes_at->not->toBeNull()
        ->isClosed()->toBeFalse();
});

/*
 * A poll that runs out on its own.
 *
 * Nothing used to run when closes_at passed — the deadline was compared where
 * the poll was read — so half of all endings were silent to anything hanging
 * off them. SettlePolls is what notices now.
 */

it('announces a poll whose own deadline has passed, once', function () {
    Event::fake([PollClosed::class]);

    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->subHour(),
    ]);

    expect(app(SettlePolls::class)->handle())->toBe(1)
        ->and($poll->refresh()->settled_at)->not->toBeNull()
        // And not stamped as stopped by hand: that distinction is what the card
        // in the channel reads, and reopen() undoes the two separately.
        ->and($poll->closed_at)->toBeNull();

    // The sweep runs every minute. Announcing the same ending sixty times an
    // hour for the rest of the poll's life is what settled_at prevents.
    expect(app(SettlePolls::class)->handle())->toBe(0);

    Event::assertDispatchedTimes(PollClosed::class, 1);
});

it('leaves a poll whose deadline is still ahead alone', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->addDay(),
    ]);

    expect(app(SettlePolls::class)->handle())->toBe(0)
        ->and($poll->refresh()->settled_at)->toBeNull();
});

it('does not announce the ending twice for a poll somebody had already stopped', function () {
    Event::fake([PollClosed::class]);

    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->subHour(),
        'closed_at' => now()->subHours(2),
    ]);

    // Pressing stop already said this poll was over. The deadline arriving
    // afterwards closed nothing that was still open.
    expect(app(SettlePolls::class)->handle())->toBe(0)
        ->and($poll->refresh()->settled_at)->not->toBeNull();

    Event::assertNotDispatched(PollClosed::class);
});

it('lets a reopened poll run out and be announced again', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closes_at' => now()->addMinutes(5),
    ]);

    app(SettlePolls::class)->handle();

    $this->travel(10)->minutes();

    expect(app(SettlePolls::class)->handle())->toBe(1);

    actingAs($user)->post(route('chat.polls.reopen', [$workspace, $poll]));

    // Reopening undoes the ending, so the note that it was dealt with has to go
    // with it — otherwise a second deadline could never be announced.
    expect($poll->refresh()->settled_at)->toBeNull();
});

/** Reopening is not a reset: answers given in good faith stay. */
it('keeps the votes that were already cast', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'closed_at' => now(),
    ]);
    $option = PollOption::factory()->create(['poll_id' => $poll->id]);
    PollVote::create(['poll_option_id' => $option->id, 'user_id' => $user->id]);

    actingAs($user)->post(route('chat.polls.reopen', [$workspace, $poll]));

    expect(PollVote::where('poll_option_id', $option->id)->count())->toBe(1);
});

it('does not let a bystander reopen somebody else poll', function () {
    [$asker, $workspace, $channel] = pollChannel();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'created_by' => $asker->id,
        'closed_at' => now(),
    ]);

    actingAs($other)
        ->post(route('chat.polls.reopen', [$workspace, $poll]))
        ->assertForbidden();

    expect($poll->refresh()->closed_at)->not->toBeNull();
});

it('sends somebody following the link into the channel', function () {
    [$user, $workspace, $channel] = pollChannel();
    $poll = Poll::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    actingAs($user)
        ->get(route('chat.polls.show', [$workspace, $poll]))
        ->assertRedirect(route('chat.show', [$workspace->slug, $channel->id], absolute: false));
});

/**
 * The composer only draws the button where a poll may actually be asked.
 * Without this the whole feature is unreachable from the interface — the same
 * gap the secret request shipped with the first time.
 */
it('offers the message field a poll where it is allowed', function () {
    [$user, $workspace, $channel] = pollChannel();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.polls', true));

    Feature::for($workspace)->deactivate(Polls::class);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.polls', false));
});
