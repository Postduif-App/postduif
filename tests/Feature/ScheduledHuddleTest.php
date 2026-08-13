<?php

use App\Actions\Huddles\AnnounceScheduledHuddles;
use App\Enums\SystemRole;
use App\Features\Huddles as HuddlesFeature;
use App\Models\Channel;
use App\Models\Message;
use App\Models\ScheduledHuddle;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A workspace with huddles switched on, and somebody in a channel of it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function scheduledHuddleFixture(): array
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    Feature::for($workspace)->activate(HuddlesFeature::class);

    return [$user, $workspace, channelWithMember($workspace, $user)];
}

it('puts a huddle in the diary without telling the channel yet', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    actingAs($user)->post(route('chat.huddles.schedule', [$workspace, $channel]), [
        'title' => 'Sprintplanning',
        'starts_at' => now()->addHours(2)->toIso8601String(),
        'duration_minutes' => 45,
    ])->assertRedirect();

    $scheduled = ScheduledHuddle::query()->sole();

    expect($scheduled->title)->toBe('Sprintplanning')
        ->and($scheduled->duration_minutes)->toBe(45)
        ->and($scheduled->isUpcoming())->toBeTrue()
        // An appointment is not news until it is nearly time. A channel told
        // twice is one where the second message goes unread.
        ->and($channel->messages()->count())->toBe(0);
});

it('tells the channel once the moment has come', function () {
    [, , $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->due()->create([
        'channel_id' => $channel->id,
        'title' => 'Sprintplanning',
    ]);

    expect(app(AnnounceScheduledHuddles::class)->handle())
        ->toBe(['announced' => 1, 'skipped' => 0]);

    $message = Message::query()->sole();

    expect($message->body)->toContain('Sprintplanning')
        // A bot line, like every other announcement in this application.
        ->and($message->bot_name)->toBe('Huddles')
        ->and($message->user_id)->toBeNull();
});

it('names the invitees so the mention machinery reaches them', function () {
    [, $workspace, $channel] = scheduledHuddleFixture();

    $invitee = User::factory()->create(['username' => 'joris']);
    joinWorkspace($workspace, $invitee);
    $channel->members()->attach($invitee->id, ['joined_at' => now()]);

    $scheduled = ScheduledHuddle::factory()->due()->create(['channel_id' => $channel->id]);
    $scheduled->invitees()->attach($invitee->id);

    app(AnnounceScheduledHuddles::class)->handle();

    // Being asked to a meeting is exactly what the inbox is for, and writing
    // the handle is how this borrows that rather than building a second one.
    expect(Message::query()->sole()->body)->toContain('@joris');
});

it('does not announce the same huddle twice', function () {
    [, , $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->due()->create(['channel_id' => $channel->id]);

    app(AnnounceScheduledHuddles::class)->handle();
    $second = app(AnnounceScheduledHuddles::class)->handle();

    expect($second)->toBe(['announced' => 0, 'skipped' => 0])
        ->and(Message::query()->count())->toBe(1);
});

it('leaves an appointment alone until its moment', function () {
    [, , $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->create([
        'channel_id' => $channel->id,
        'starts_at' => now()->addHour(),
    ]);

    expect(app(AnnounceScheduledHuddles::class)->handle())
        ->toBe(['announced' => 0, 'skipped' => 0])
        ->and(Message::query()->count())->toBe(0);
});

it('lets an appointment lapse when the workspace switched huddles off', function () {
    [, $workspace, $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->due()->create(['channel_id' => $channel->id]);

    Feature::for($workspace)->deactivate(HuddlesFeature::class);

    // Quietly, and without a message inviting people into a room that no
    // longer exists.
    expect(app(AnnounceScheduledHuddles::class)->handle())
        ->toBe(['announced' => 0, 'skipped' => 1])
        ->and(Message::query()->count())->toBe(0);
});

it('lets an appointment lapse when the channel has been archived', function () {
    [, , $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->due()->create(['channel_id' => $channel->id]);

    $channel->forceFill(['archived_at' => now()])->save();

    expect(app(AnnounceScheduledHuddles::class)->handle())
        ->toBe(['announced' => 0, 'skipped' => 1])
        ->and(Message::query()->count())->toBe(0);
});

it('never announces one that was called off', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    $scheduled = ScheduledHuddle::factory()->due()->create([
        'channel_id' => $channel->id,
        'created_by' => $user->id,
    ]);

    actingAs($user)->delete(route('chat.huddles.schedule.destroy', [$workspace, $scheduled]))
        ->assertRedirect();

    expect($scheduled->fresh()->cancelled_at)->not->toBeNull()
        ->and(app(AnnounceScheduledHuddles::class)->handle())
        ->toBe(['announced' => 0, 'skipped' => 0])
        ->and(Message::query()->count())->toBe(0);
});

it('sends the diary to the channel screen, with who may call each one off', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    $mine = ScheduledHuddle::factory()->create([
        'channel_id' => $channel->id,
        'created_by' => $user->id,
        'title' => 'Sprintplanning',
    ]);

    actingAs($user)->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('channel.scheduledHuddles', 1)
            ->where('channel.scheduledHuddles.0.id', $mine->id)
            ->where('channel.scheduledHuddles.0.title', 'Sprintplanning')
            // Worked out on the server, so the screen cannot offer a button
            // that then refuses.
            ->where('channel.scheduledHuddles.0.canCancel', true));
});

it('drops an announced appointment out of the diary', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    ScheduledHuddle::factory()->announced()->create(['channel_id' => $channel->id]);

    // Once the channel has been told, the appointment *is* the conversation —
    // a diary entry beside a live huddle would be two things claiming to be
    // the same meeting.
    actingAs($user)->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('channel.scheduledHuddles', 0));
});

it('refuses to invite somebody who is not in the channel', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider);

    actingAs($user)->post(route('chat.huddles.schedule', [$workspace, $channel]), [
        'title' => 'Overleg',
        'starts_at' => now()->addHour()->toIso8601String(),
        'duration_minutes' => 30,
        'invitees' => [$outsider->id],
    ])->assertSessionHasErrors('invitees.0');

    expect(ScheduledHuddle::query()->count())->toBe(0);
});

it('refuses a moment that has already passed', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    actingAs($user)->post(route('chat.huddles.schedule', [$workspace, $channel]), [
        'title' => 'Overleg',
        'starts_at' => now()->subHour()->toIso8601String(),
        'duration_minutes' => 30,
    ])->assertSessionHasErrors('starts_at');

    expect(ScheduledHuddle::query()->count())->toBe(0);
});

it('lets only the organiser or whoever runs the channel call one off', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    $someoneElse = User::factory()->create();
    joinWorkspace($workspace, $someoneElse, SystemRole::Member);
    $channel->members()->attach($someoneElse->id, ['joined_at' => now()]);

    $scheduled = ScheduledHuddle::factory()->create([
        'channel_id' => $channel->id,
        'created_by' => $user->id,
    ]);

    // Being invited is not the same as being able to take the meeting away
    // from the other four people.
    actingAs($someoneElse)->delete(route('chat.huddles.schedule.destroy', [$workspace, $scheduled]))
        ->assertForbidden();

    actingAs($user)->delete(route('chat.huddles.schedule.destroy', [$workspace, $scheduled]))
        ->assertRedirect();
});

it('refuses to schedule where the workspace has huddles switched off', function () {
    [$user, $workspace, $channel] = scheduledHuddleFixture();

    Feature::for($workspace)->deactivate(HuddlesFeature::class);

    actingAs($user)->post(route('chat.huddles.schedule', [$workspace, $channel]), [
        'title' => 'Overleg',
        'starts_at' => now()->addHour()->toIso8601String(),
        'duration_minutes' => 30,
    ])->assertNotFound();

    expect(ScheduledHuddle::query()->count())->toBe(0);
});
