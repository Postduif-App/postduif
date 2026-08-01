<?php

use App\Models\StatusRule;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('adds a rule underneath the ones already there', function () {
    $user = User::factory()->create();
    StatusRule::factory()->for($user)->create(['position' => 0]);

    actingAs($user)->post(route('status-rules.store'), [
        'days' => [1, 2, 3, 4, 5],
        'starts_at' => '09:00',
        'ends_at' => '17:00',
        'status_emoji' => '💼',
        'status_text' => 'Aan het werk',
        'availability' => 'available',
    ])->assertRedirect();

    // Underneath, so it cannot silently outrank something that was working.
    // Queried directly: the relation orders by position, so asking it for the
    // newest would sort by position first and hand back the older rule.
    expect(StatusRule::query()->where('status_text', 'Aan het werk')->sole()->position)
        ->toBe(1);
});

it('takes a window that runs through midnight', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('status-rules.store'), [
        'days' => [1],
        'starts_at' => '22:00',
        'ends_at' => '06:00',
        'availability' => 'away',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->statusRules()->sole()->starts_at)->toStartWith('22:00');
});

it('changes a rule', function () {
    $user = User::factory()->create();
    $rule = StatusRule::factory()->for($user)->create(['status_text' => 'Oud']);

    actingAs($user)->patch(route('status-rules.update', $rule), [
        'days' => [],
        'starts_at' => null,
        'ends_at' => null,
        'status_text' => 'Nieuw',
        'availability' => 'available',
    ])->assertRedirect();

    expect($rule->refresh()->status_text)->toBe('Nieuw');
});

it('puts the rules in a new order', function () {
    $user = User::factory()->create();
    $first = StatusRule::factory()->for($user)->create(['position' => 0]);
    $second = StatusRule::factory()->for($user)->create(['position' => 1]);

    actingAs($user)->put(route('status-rules.reorder'), [
        'ids' => [$second->id, $first->id],
    ])->assertRedirect();

    expect($first->refresh()->position)->toBe(1)
        ->and($second->refresh()->position)->toBe(0);
});

/** Somebody else's id in the list must not move somebody else's rule. */
it('ignores an id that is not theirs when reordering', function () {
    $user = User::factory()->create();
    $own = StatusRule::factory()->for($user)->create(['position' => 0]);
    $strangers = StatusRule::factory()->create(['position' => 0]);

    actingAs($user)->put(route('status-rules.reorder'), [
        'ids' => [$strangers->id, $own->id],
    ])->assertRedirect();

    expect($own->refresh()->position)->toBe(0)
        ->and($strangers->refresh()->position)->toBe(0);
});

it('refuses to touch somebody else their rule', function () {
    $rule = StatusRule::factory()->create();

    // 404 rather than 403, so an id cannot be probed for existence.
    actingAs(User::factory()->create())
        ->delete(route('status-rules.destroy', $rule))
        ->assertNotFound();

    expect(StatusRule::whereKey($rule->id)->exists())->toBeTrue();
});

it('deletes a rule of your own', function () {
    $user = User::factory()->create();
    $rule = StatusRule::factory()->for($user)->create();

    actingAs($user)->delete(route('status-rules.destroy', $rule))->assertRedirect();

    expect(StatusRule::count())->toBe(0);
});

it('shows which rule is in force at this moment', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $always = StatusRule::factory()->for($user)->create(['position' => 0]);

    actingAs($user)
        ->get(route('status-rules.index'))
        ->assertInertia(fn ($page) => $page
            ->where('activeRuleId', $always->id)
            ->where('timezone', 'Europe/Amsterdam')
            ->has('rules', 1)
        );
});

/** The times come back in the shape a time field takes, not Postgres's. */
it('hands the times over as the form wants them', function () {
    $user = User::factory()->create();
    StatusRule::factory()->workdays()->for($user)->create();

    actingAs($user)
        ->get(route('status-rules.index'))
        ->assertInertia(fn ($page) => $page
            ->where('rules.0.startsAt', '09:00')
            ->where('rules.0.endsAt', '17:00')
        );
});

it('refuses a weekday that is not a weekday', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('status-rules.store'), [
        'days' => [8],
        'availability' => 'available',
    ])->assertSessionHasErrors('days.0');
});
