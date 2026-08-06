<?php

use App\Actions\Timeclock\ClockIn;
use App\Actions\Timeclock\ClockOut;
use App\Actions\Timeclock\SummariseHours;
use App\Enums\Availability;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

it('records a shift from clocking in to clocking out', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 07:00:00');
    actingAs($user)->post(route('chat.timeclock.clock-in', $workspace))->assertRedirect();

    Carbon::setTestNow('2026-08-05 15:30:00');
    actingAs($user)->post(route('chat.timeclock.clock-out', $workspace))->assertRedirect();

    $entry = TimeEntry::firstOrFail();

    expect($entry->workspace_id)->toBe($workspace->id)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->isRunning())->toBeFalse()
        ->and($entry->seconds())->toBe(8 * 3600 + 1800);
});

it('hands back the shift that is already running rather than opening a second', function () {
    [$user, $workspace] = clockingMember();

    $clockIn = app(ClockIn::class);

    $first = $clockIn->handle($user, $workspace);
    $second = $clockIn->handle($user, $workspace);

    expect($second->id)->toBe($first->id)
        ->and(TimeEntry::count())->toBe(1);
});

it('refuses a second open shift at the database', function () {
    [$user, $workspace] = clockingMember();

    TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    expect(fn () => TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('lets the same member have an open shift in two workspaces', function () {
    [$user, $workspace] = clockingMember();
    $other = workspaceWithMember($user);

    TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    TimeEntry::factory()->running()->create([
        'workspace_id' => $other->id,
        'user_id' => $user->id,
    ]);

    expect(TimeEntry::count())->toBe(2);
});

it('says nothing was running rather than failing when there is nothing to close', function () {
    [$user, $workspace] = clockingMember();

    expect(app(ClockOut::class)->handle($user, $workspace))->toBeNull();

    actingAs($user)->post(route('chat.timeclock.clock-out', $workspace))->assertRedirect();
});

it('keeps the clock away from a workspace that has not switched it on', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    // A 404 rather than a 403: the feature middleware guards the whole group,
    // so for a workspace without tijdregistratie the address does not exist.
    actingAs($user)->post(route('chat.timeclock.clock-in', $workspace))->assertNotFound();
    actingAs($user)->get(route('chat.timeclock.index', $workspace))->assertNotFound();
});

it('keeps a guest off the clock', function () {
    [, $workspace] = clockingMember();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    actingAs($guest)->post(route('chat.timeclock.clock-in', $workspace))->assertForbidden();
});

it('counts a forgotten shift up to the ceiling and no further', function () {
    [$user, $workspace] = clockingMember();

    $entry = TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(3),
    ]);

    expect($entry->seconds())->toBe(TimeEntry::MAX_SHIFT_HOURS * 3600);
});

it('adds up a week on the member own clock', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    // Monday and Tuesday of the week that Wednesday falls in.
    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-03 07:00:00'),
        'ended_at' => Carbon::parse('2026-08-03 15:00:00'),
    ]);
    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-04 07:00:00'),
        'ended_at' => Carbon::parse('2026-08-04 11:00:00'),
    ]);
    // The Friday before, which belongs to the week before.
    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-07-31 07:00:00'),
        'ended_at' => Carbon::parse('2026-07-31 15:00:00'),
    ]);

    $week = app(SummariseHours::class)->forMember($user, $workspace);

    expect($week['seconds'])->toBe(12 * 3600)
        ->and($week['from'])->toBe('2026-08-03')
        ->and($week['until'])->toBe('2026-08-09')
        ->and($week['entries'])->toHaveCount(2)
        ->and($week['days'])->toHaveCount(7);
});

it('counts a night shift towards the day it began on', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-03 20:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-04 04:00:00', 'Europe/Amsterdam'),
    ]);

    $week = app(SummariseHours::class)->forMember($user, $workspace);

    $monday = collect($week['days'])->firstWhere('date', '2026-08-03');
    $tuesday = collect($week['days'])->firstWhere('date', '2026-08-04');

    expect($monday['seconds'])->toBe(8 * 3600)
        ->and($tuesday['seconds'])->toBe(0);
});

it('walks back to an earlier week', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-07-28 07:00:00'),
        'ended_at' => Carbon::parse('2026-07-28 15:00:00'),
    ]);

    actingAs($user)->get(route('chat.timeclock.index', [$workspace, 'weeks' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/timeclock')
            ->where('week.from', '2026-07-27')
            ->where('week.seconds', 8 * 3600));
});

it('moves the availability of a member who asked for it', function () {
    [$user, $workspace] = clockingMember();
    $user->forceFill(['clock_sets_status' => true, 'availability' => Availability::Away])->save();

    app(ClockIn::class)->handle($user, $workspace);
    expect($user->refresh()->availability)->toBe(Availability::Available);

    app(ClockOut::class)->handle($user, $workspace);
    expect($user->refresh()->availability)->toBe(Availability::Away);
});

it('leaves the status alone for a member who did not ask', function () {
    [$user, $workspace] = clockingMember();
    $user->forceFill(['availability' => Availability::DoNotDisturb, 'status_text' => 'aan het schrijven'])->save();

    app(ClockIn::class)->handle($user, $workspace);

    expect($user->refresh()->availability)->toBe(Availability::DoNotDisturb)
        ->and($user->status_text)->toBe('aan het schrijven');
});

it('keeps the words of a status it moves', function () {
    [$user, $workspace] = clockingMember();
    $user->forceFill([
        'clock_sets_status' => true,
        'availability' => Availability::Away,
        'status_emoji' => '📚',
        'status_text' => 'aan het lezen',
    ])->save();

    app(ClockIn::class)->handle($user, $workspace);

    expect($user->refresh()->status_text)->toBe('aan het lezen')
        ->and($user->status_emoji)->toBe('📚')
        ->and($user->availability)->toBe(Availability::Available);
});

it('stores the preference from the screen', function () {
    [$user, $workspace] = clockingMember();

    actingAs($user)->patch(route('chat.timeclock.preference', $workspace), ['setsStatus' => true])
        ->assertRedirect();

    expect($user->refresh()->clock_sets_status)->toBeTrue();
});

it('shows colleagues only to whoever holds the right', function () {
    [$user, $workspace] = clockingMember();

    actingAs($user)->get(route('chat.timeclock.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('colleagues', null));

    $role = $workspace->roleFor($user);
    $role->forceFill(['abilities' => [...$role->abilities, WorkspaceAbility::SeeHours->value]])->save();

    $colleague = User::factory()->create(['name' => 'Wilma']);
    joinWorkspace($workspace, $colleague);
    TimeEntry::factory()->running()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $colleague->id,
    ]);

    actingAs($user)->get(route('chat.timeclock.index', $workspace))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('colleagues.0.name', 'Wilma')
            ->where('colleagues.0.running', true));
});

it('paints a day darker the longer it was worked', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    // An hour on Monday, a full day on Tuesday.
    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-03 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-03 10:00:00', 'Europe/Amsterdam'),
    ]);
    TimeEntry::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-08-04 09:00:00', 'Europe/Amsterdam'),
        'ended_at' => Carbon::parse('2026-08-04 17:00:00', 'Europe/Amsterdam'),
    ]);

    $calendar = app(SummariseHours::class)->calendar($user, $workspace, 4);

    $days = collect($calendar['weeks'])->flatten(1)->keyBy('date');

    expect($calendar['weeks'])->toHaveCount(4)
        ->and($calendar['weeks'][0])->toHaveCount(7)
        ->and($days['2026-08-03']['level'])->toBe(1)
        ->and($days['2026-08-04']['level'])->toBe(4)
        ->and($days['2026-08-05']['level'])->toBe(0)
        // Thursday of the week we are in has not happened yet: no square, and
        // not an empty one either.
        ->and($days['2026-08-06']['future'])->toBeTrue()
        ->and($days['2026-08-03']['future'])->toBeFalse();
});

it('shows the same half year whichever week is being read', function () {
    [$user, $workspace] = clockingMember();

    Carbon::setTestNow('2026-08-05 12:00:00');

    actingAs($user)->get(route('chat.timeclock.index', [$workspace, 'weeks' => 3]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('week.from', '2026-07-13')
            // The chart still ends on the week we are actually in — it is the
            // map you navigate with, not a second week view.
            ->where('calendar.until', '2026-08-09'));
});
