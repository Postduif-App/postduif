<?php

use App\Enums\SystemRole;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/**
 * Correcting what the clock recorded.
 *
 * The times go in as wall clock readings on the member's own clock, which is
 * what the form asks for — so every test here sets a zone and then reasons in
 * it, exactly as somebody filling the form in would.
 */
it('adjusts the times of a finished stretch', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 17:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-05',
        'startedAt' => '08:30',
        'endedAt' => '17:00',
    ])->assertRedirect();

    $entry->refresh();

    expect($entry->seconds())->toBe(8 * 3600 + 1800)
        ->and($entry->wasCorrected())->toBeTrue()
        ->and($entry->onMemberClock($entry->started_at, $user)->format('H:i'))->toBe('08:30');
});

it('reads a corrected end that falls before its start as the next morning', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-04 22:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 02:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-04',
        'startedAt' => '22:00',
        'endedAt' => '06:00',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->refresh()->seconds())->toBe(8 * 3600)
        // Still Tuesday's shift: a night belongs to the evening it began in.
        ->and($entry->localDate($user))->toBe('2026-08-04');
});

it('refuses a correction that lands in the future', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 08:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 11:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-05',
        'startedAt' => '08:00',
        'endedAt' => '23:00',
    ])->assertSessionHasErrors('endedAt');

    expect($entry->refresh()->wasCorrected())->toBeFalse();
});

it('refuses a correction that sits on top of another stretch', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 12:00:00', 'Europe/Amsterdam'),
    ]);

    $afternoon = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 13:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 17:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $afternoon]), [
        'date' => '2026-08-05',
        'startedAt' => '11:00',
        'endedAt' => '17:00',
    ])->assertSessionHasErrors('startedAt');
});

it('refuses to reopen a stretch that was already closed', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 18:00:00');

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-05 17:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-05',
        'startedAt' => '09:00',
        'endedAt' => null,
    ])->assertSessionHasErrors('endedAt');

    expect($entry->refresh()->isRunning())->toBeFalse();
});

it('corrects the start of a shift that is still running', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    $entry = TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-05 10:00:00', 'Europe/Amsterdam'),
    ]);

    actingAs($user)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-05',
        'startedAt' => '08:00',
        'endedAt' => null,
    ])->assertRedirect()->assertSessionHasNoErrors();

    // Six, not four: the frozen moment is noon UTC, which is two in the
    // afternoon on the clock this member reads.
    expect($entry->refresh()->isRunning())->toBeTrue()
        ->and($entry->seconds())->toBe(6 * 3600);
});

it('removes a stretch that was never worked', function () {
    [$user, $workspace] = clockingMember();

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)->delete(route('chat.timeclock.entries.destroy', [$workspace, $entry]))->assertRedirect();

    expect(TimeEntry::count())->toBe(0);
});

it('keeps everybody else away from somebody hours', function () {
    [$user, $workspace] = clockingMember();

    $entry = TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    // An admin of the very same workspace. Reading somebody's hours is a right
    // a workspace can hand out; writing them is nobody else's business.
    $admin = User::factory()->create();
    joinWorkspace($workspace, $admin, SystemRole::Admin);

    actingAs($admin)->patch(route('chat.timeclock.entries.update', [$workspace, $entry]), [
        'date' => '2026-08-05',
        'startedAt' => '08:00',
        'endedAt' => '17:00',
    ])->assertForbidden();

    actingAs($admin)->delete(route('chat.timeclock.entries.destroy', [$workspace, $entry]))->assertForbidden();
});
