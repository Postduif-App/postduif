<?php

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * A poll with two answers.
 *
 * @return array{0: Poll, 1: PollOption, 2: PollOption}
 */
function pollWithOptions(array $state = []): array
{
    $poll = Poll::factory()->create($state);

    $dinsdag = PollOption::factory()->create([
        'poll_id' => $poll->id,
        'label' => 'Dinsdag',
        'position' => 0,
    ]);
    $woensdag = PollOption::factory()->create([
        'poll_id' => $poll->id,
        'label' => 'Woensdag',
        'position' => 1,
    ]);

    return [$poll->refresh(), $dinsdag, $woensdag];
}

it('keeps the answers in the order they were asked', function () {
    [$poll] = pollWithOptions();

    expect($poll->options->pluck('label')->all())->toBe(['Dinsdag', 'Woensdag']);
});

it('refuses the same answer twice in one poll', function () {
    [$poll] = pollWithOptions();

    expect(fn () => PollOption::factory()->create([
        'poll_id' => $poll->id,
        'label' => 'Dinsdag',
    ]))->toThrow(QueryException::class);
});

/** Clicking twice is one vote, and the database is what says so. */
it('refuses the same person twice on one answer', function () {
    [, $dinsdag] = pollWithOptions();
    $user = User::factory()->create();

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $user->id]);

    // Nothing asserted after the throw: Postgres marks the transaction as
    // aborted once a constraint fires.
    expect(fn () => PollVote::create([
        'poll_option_id' => $dinsdag->id,
        'user_id' => $user->id,
    ]))->toThrow(QueryException::class);
});

it('counts the votes across every answer', function () {
    [$poll, $dinsdag, $woensdag] = pollWithOptions();

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => User::factory()->create()->id]);
    PollVote::create(['poll_option_id' => $woensdag->id, 'user_id' => User::factory()->create()->id]);

    expect($poll->votes()->count())->toBe(2);
});

/**
 * How many people answered is not how many votes were cast: on a
 * multiple-choice poll one person may tick three boxes, and the first number is
 * the one anybody means.
 */
it('counts people rather than ticks', function () {
    [$poll, $dinsdag, $woensdag] = pollWithOptions(['allows_multiple' => true]);
    $user = User::factory()->create();

    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => $user->id]);
    PollVote::create(['poll_option_id' => $woensdag->id, 'user_id' => $user->id]);

    expect($poll->votes()->count())->toBe(2)
        ->and($poll->voterCount())->toBe(1);
});

it('is open until something closes it', function () {
    [$poll] = pollWithOptions();

    expect($poll->isClosed())->toBeFalse();
});

/** Two ways to be shut, kept apart so the card can say which it was. */
it('is closed when somebody stopped it', function () {
    [$poll] = pollWithOptions(['closed_at' => now()->subHour()]);

    expect($poll->isClosed())->toBeTrue();
});

it('is closed when its moment has passed', function () {
    [$poll] = pollWithOptions(['closes_at' => now()->subHour()]);

    expect($poll->isClosed())->toBeTrue()
        // Nobody stopped it; it simply ran out.
        ->and($poll->closed_at)->toBeNull();
});

it('is still open before its moment arrives', function () {
    [$poll] = pollWithOptions(['closes_at' => now()->addHour()]);

    expect($poll->isClosed())->toBeFalse();
});

it('finds the open ones in SQL as it does in PHP', function () {
    [$open] = pollWithOptions();
    pollWithOptions(['closed_at' => now()]);
    pollWithOptions(['closes_at' => now()->subMinute()]);

    expect(Poll::open()->pluck('id')->all())->toBe([$open->id]);
});

it('takes its answers and their votes with it', function () {
    [$poll, $dinsdag] = pollWithOptions();
    PollVote::create(['poll_option_id' => $dinsdag->id, 'user_id' => User::factory()->create()->id]);

    $poll->delete();

    expect(PollOption::count())->toBe(0)
        ->and(PollVote::count())->toBe(0);
});

/** A question the channel already answered should not vanish with its asker. */
it('outlives the person who asked it', function () {
    $asker = User::factory()->create();
    [$poll] = pollWithOptions(['created_by' => $asker->id]);

    $asker->delete();

    expect($poll->refresh()->created_by)->toBeNull();
});
