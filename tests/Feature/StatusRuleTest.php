<?php

use App\Enums\Availability;
use App\Models\StatusRule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Somebody in Amsterdam, so a UTC instant and their own clock disagree by an
 * hour or two — which is the entire reason any of this exists.
 */
function ruleOwner(string $timezone = 'Europe/Amsterdam'): User
{
    return User::factory()->create(['timezone' => $timezone]);
}

it('reads a rule against the clock the member is on, not the server', function () {
    $user = ruleOwner();
    StatusRule::factory()->workdays()->for($user)->create();

    // 08:30 UTC is 10:30 in Amsterdam: inside office hours there, outside here.
    expect($user->activeStatusRule(Carbon::parse('2026-08-03 08:30', 'UTC')))->not->toBeNull();

    // And 16:30 UTC is 18:30 there, which is not.
    expect($user->activeStatusRule(Carbon::parse('2026-08-03 16:30', 'UTC')))->toBeNull();
});

it('leaves the weekend alone', function () {
    $user = ruleOwner();
    StatusRule::factory()->workdays()->for($user)->create();

    // Saturday, in the middle of what would be a working day.
    expect($user->activeStatusRule(Carbon::parse('2026-08-01 12:00', 'Europe/Amsterdam')))->toBeNull();
});

/**
 * The example from the issue: one rule for office hours and one underneath it
 * for everything else. No separate "outside these hours" setting — it is the
 * same kind of thing, one line lower.
 */
it('falls through to the rule underneath', function () {
    $user = ruleOwner();

    StatusRule::factory()->workdays()->for($user)->create([
        'position' => 0,
        'status_text' => 'Aan het werk',
    ]);
    StatusRule::factory()->for($user)->create([
        'position' => 1,
        'status_text' => 'Niet aan het werk',
        'availability' => Availability::Away,
    ]);

    expect($user->activeStatusRule(Carbon::parse('2026-08-03 10:00', 'Europe/Amsterdam'))?->status_text)
        ->toBe('Aan het werk')
        ->and($user->activeStatusRule(Carbon::parse('2026-08-03 20:00', 'Europe/Amsterdam'))?->status_text)
        ->toBe('Niet aan het werk')
        // And on a Saturday the first rule does not apply at all.
        ->and($user->activeStatusRule(Carbon::parse('2026-08-01 10:00', 'Europe/Amsterdam'))?->status_text)
        ->toBe('Niet aan het werk');
});

it('takes the first match even when a later rule also fits', function () {
    $user = ruleOwner();

    StatusRule::factory()->for($user)->create(['position' => 0, 'status_text' => 'Bovenste']);
    StatusRule::factory()->for($user)->create(['position' => 1, 'status_text' => 'Onderste']);

    expect($user->activeStatusRule()?->status_text)->toBe('Bovenste');
});

/**
 * A window that ends before it starts runs through midnight, and belongs to the
 * day it began on: "maandagavond" is Monday, including the part of it that is
 * technically Tuesday.
 */
it('carries an evening rule past midnight into the next morning', function () {
    $user = ruleOwner();

    StatusRule::factory()->for($user)->create([
        'days' => [1],
        'starts_at' => '22:00',
        'ends_at' => '06:00',
    ]);

    $amsterdam = fn (string $when): Carbon => Carbon::parse($when, 'Europe/Amsterdam');

    // Monday night, and the small hours of Tuesday that belong to it.
    expect($user->activeStatusRule($amsterdam('2026-08-03 23:00')))->not->toBeNull()
        ->and($user->activeStatusRule($amsterdam('2026-08-04 02:00')))->not->toBeNull()
        // But not Monday morning, which is the other reading of "Monday".
        ->and($user->activeStatusRule($amsterdam('2026-08-03 02:00')))->toBeNull()
        // And not once Tuesday has properly started.
        ->and($user->activeStatusRule($amsterdam('2026-08-04 07:00')))->toBeNull();
});

it('treats a rule with no days and no hours as always', function () {
    $user = ruleOwner();
    StatusRule::factory()->for($user)->create();

    expect($user->activeStatusRule(Carbon::parse('2026-08-01 03:14', 'UTC')))->not->toBeNull();
});

/**
 * Nothing here converts anything, which is what makes the clock changes behave.
 * On the night the clocks go forward, no clock in Amsterdam ever reads 02:30 —
 * so a rule at that time simply does not happen that night, which is exactly
 * what a person looking at their own clock would say.
 */
it('does not fire at a time the clock skipped', function () {
    $user = ruleOwner();

    StatusRule::factory()->for($user)->create([
        'starts_at' => '02:15',
        'ends_at' => '02:45',
    ]);

    // 01:30 UTC on the spring-forward night: Amsterdam jumps 02:00 to 03:00,
    // so this instant reads 03:30 there and the window never opens.
    expect($user->activeStatusRule(Carbon::parse('2026-03-29 01:30', 'UTC')))->toBeNull();

    // The same wall clock on any ordinary night does fire.
    expect($user->activeStatusRule(Carbon::parse('2026-03-22 02:30', 'Europe/Amsterdam')))->not->toBeNull();
});

it('has nothing to say for somebody with no rules', function () {
    expect(ruleOwner()->activeStatusRule())->toBeNull();
});

it('reads the same rule differently for somebody elsewhere', function () {
    $amsterdam = ruleOwner();
    $tokyo = ruleOwner('Asia/Tokyo');

    foreach ([$amsterdam, $tokyo] as $user) {
        StatusRule::factory()->workdays()->for($user)->create();
    }

    // 08:00 UTC: 10:00 in Amsterdam, 17:00 in Tokyo — where the day is over.
    $moment = Carbon::parse('2026-08-03 08:00', 'UTC');

    expect($amsterdam->activeStatusRule($moment))->not->toBeNull()
        ->and($tokyo->activeStatusRule($moment))->toBeNull();
});
