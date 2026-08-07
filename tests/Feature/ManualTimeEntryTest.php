<?php

use App\Events\ClockPunched;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * Writing down a day the clock never saw.
 *
 * The times go in as wall clock readings on the member's own clock, which is
 * what the form asks for — so every test here sets a zone and then reasons in
 * it, exactly as somebody filling the form in would.
 */
it('adds a stretch by hand', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-04',
        'startedAt' => '09:00',
        'endedAt' => '17:30',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = TimeEntry::sole();

    expect($entry->user_id)->toBe($user->id)
        ->and($entry->workspace_id)->toBe($workspace->id)
        ->and($entry->seconds())->toBe(8 * 3600 + 1800)
        ->and($entry->localDate($user))->toBe('2026-08-04')
        ->and($entry->onMemberClock($entry->started_at, $user)->format('H:i'))->toBe('09:00')
        // Typed rather than clocked, and the week says so out loud.
        ->and($entry->wasCorrected())->toBeTrue();
});

it('reads an added end that falls before its start as the next morning', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-04',
        'startedAt' => '22:00',
        'endedAt' => '06:00',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = TimeEntry::sole();

    expect($entry->seconds())->toBe(8 * 3600)
        // Still Tuesday's shift: a night belongs to the evening it began in.
        ->and($entry->localDate($user))->toBe('2026-08-04');
});

it('refuses an added stretch that sits on top of one already recorded', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 12:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-05',
        'startedAt' => '11:00',
        'endedAt' => '17:00',
    ])->assertSessionHasErrors('startedAt');

    expect(TimeEntry::count())->toBe(1);
});

it('refuses an added stretch without an end', function () {
    [$user, $workspace] = clockingMember();

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-05',
        'startedAt' => '09:00',
        'endedAt' => null,
    ])->assertSessionHasErrors('endedAt');

    expect(TimeEntry::count())->toBe(0);
});

it('refuses an added stretch that lands in the future', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-05',
        'startedAt' => '08:00',
        'endedAt' => '23:00',
    ])->assertSessionHasErrors('endedAt');

    expect(TimeEntry::count())->toBe(0);
});

it('leaves the clock alone when a stretch is typed in', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    Event::fake([ClockPunched::class]);

    actingAs($user)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-04',
        'startedAt' => '09:00',
        'endedAt' => '17:00',
    ])->assertRedirect();

    // Filling in last Tuesday is not a punch happening now, so nothing that
    // listens for one — a workflow, somebody's status — hears about it.
    Event::assertNotDispatched(ClockPunched::class);

    expect(TimeEntry::sole()->isRunning())->toBeFalse();
});

it('keeps somebody without a clock from writing hours in a workspace', function () {
    [, $workspace] = clockingMember();

    $outsider = User::factory()->create();

    actingAs($outsider)->post(route('chat.timeclock.entries.store', $workspace), [
        'date' => '2026-08-05',
        'startedAt' => '09:00',
        'endedAt' => '17:00',
    ])->assertForbidden();

    expect(TimeEntry::count())->toBe(0);
});
