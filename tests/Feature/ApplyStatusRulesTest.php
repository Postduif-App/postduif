<?php

use App\Actions\Users\ApplyStatusRules;
use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Models\StatusRule;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Run the applier as if it were the given local moment in Amsterdam. */
function applyStatusesAt(string $localMoment): array
{
    Carbon::setTestNow(Carbon::parse($localMoment, 'Europe/Amsterdam'));

    $counts = app(ApplyStatusRules::class)->handle();

    Carbon::setTestNow();

    return $counts;
}

function scheduledMember(): User
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);

    StatusRule::factory()->workdays()->for($user)->create([
        'position' => 0,
        'status_emoji' => '💼',
        'status_text' => 'Aan het werk',
        'availability' => Availability::Available,
    ]);

    return $user;
}

it('sets the status the schedule calls for', function () {
    $user = scheduledMember();

    // Monday morning.
    applyStatusesAt('2026-08-03 10:00');

    $user->refresh();

    expect($user->status_text)->toBe('Aan het werk')
        ->and($user->status_emoji)->toBe('💼')
        ->and($user->status_is_manual)->toBeFalse();
});

it('takes it away again once the window has passed', function () {
    $user = scheduledMember();

    applyStatusesAt('2026-08-03 10:00');
    applyStatusesAt('2026-08-03 18:00');

    expect($user->refresh()->status_text)->toBeNull();
});

it('does nothing on a second run in the same window', function () {
    scheduledMember();

    applyStatusesAt('2026-08-03 10:00');

    expect(applyStatusesAt('2026-08-03 10:01'))->toBe(['applied' => 0, 'cleared' => 0]);
});

/**
 * The point of the whole arrangement: your own words win, but only until the
 * schedule reaches its next boundary. Nobody has to remember to undo themselves.
 */
it('leaves a status somebody typed themselves alone inside the same window', function () {
    $user = scheduledMember();

    applyStatusesAt('2026-08-03 10:00');

    Carbon::setTestNow(Carbon::parse('2026-08-03 11:00', 'Europe/Amsterdam'));
    app(SetStatus::class)->handle($user, '📞', 'Even bellen', Availability::DoNotDisturb);
    Carbon::setTestNow();

    applyStatusesAt('2026-08-03 12:00');

    expect($user->refresh()->status_text)->toBe('Even bellen');
});

it('takes over again at the next boundary', function () {
    $user = scheduledMember();

    applyStatusesAt('2026-08-03 10:00');

    Carbon::setTestNow(Carbon::parse('2026-08-03 11:00', 'Europe/Amsterdam'));
    app(SetStatus::class)->handle($user, '📞', 'Even bellen', Availability::DoNotDisturb);
    Carbon::setTestNow();

    // Five o'clock: the working window is over, so the override is too.
    applyStatusesAt('2026-08-03 17:30');

    expect($user->refresh()->status_text)->toBeNull();
});

/**
 * Somebody who typed a status where no rule reaches meant it. The scheduler is
 * not a tidying service for statuses it did not put there.
 */
it('leaves a status alone that no rule ever covered', function () {
    $user = scheduledMember();

    // Saturday, outside every rule this member has.
    Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'Europe/Amsterdam'));
    app(SetStatus::class)->handle($user, '🏖️', 'Weekend', Availability::Away);
    Carbon::setTestNow();

    expect(applyStatusesAt('2026-08-01 13:00'))->toBe(['applied' => 0, 'cleared' => 0])
        ->and($user->refresh()->status_text)->toBe('Weekend');
});

it('does not offer a scheduled status back as a shortcut', function () {
    $user = scheduledMember();

    applyStatusesAt('2026-08-03 10:00');

    // The recent list is for statuses somebody chose; nobody chose this one.
    expect($user->refresh()->recent_statuses)->toBe([]);
});

it('leaves somebody without rules entirely alone', function () {
    $user = User::factory()->create();

    app(SetStatus::class)->handle($user, '☕', 'Koffie', Availability::Available);

    expect(applyStatusesAt('2026-08-03 10:00'))->toBe(['applied' => 0, 'cleared' => 0])
        ->and($user->refresh()->status_text)->toBe('Koffie');
});

it('moves from one rule to the next without an empty moment in between', function () {
    $user = scheduledMember();

    StatusRule::factory()->for($user)->create([
        'position' => 1,
        'status_emoji' => '🌙',
        'status_text' => 'Buiten werktijd',
        'availability' => Availability::Away,
    ]);

    applyStatusesAt('2026-08-03 10:00');
    expect($user->refresh()->status_text)->toBe('Aan het werk');

    applyStatusesAt('2026-08-03 18:00');
    expect($user->refresh()->status_text)->toBe('Buiten werktijd');
});
