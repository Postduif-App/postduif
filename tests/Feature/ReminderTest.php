<?php

use App\Actions\Chat\DeliverDueReminders;
use App\Actions\Chat\ScheduleReminder;
use App\Enums\ChannelType;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/**
 * Somebody in a channel, and a message in it worth being reminded of.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message}
 */
function reminderFixture(): array
{
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    return [$user, $workspace, $channel, $message];
}

it('sets a reminder on a message and leaves the inbox alone until it is due', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => '1h',
    ])->assertRedirect();

    $reminder = Reminder::query()->sole();

    expect($reminder->user_id)->toBe($user->id)
        ->and($reminder->message_id)->toBe($message->id)
        ->and($reminder->channel_id)->toBe($channel->id)
        ->and($reminder->isPending())->toBeTrue()
        // Nothing is waiting yet: a reminder is made now for later, and an
        // inbox that showed it straight away would defeat the whole point.
        ->and(InboxItem::query()->count())->toBe(0);
});

it('puts the message in the inbox once the moment has come', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->due()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    $result = app(DeliverDueReminders::class)->handle();

    expect($result)->toBe(['delivered' => 1, 'dropped' => 0]);

    $item = InboxItem::query()->sole();

    expect($item->type)->toBe(InboxItemType::Reminder)
        ->and($item->user_id)->toBe($user->id)
        ->and($item->message_id)->toBe($message->id)
        ->and($item->read_at)->toBeNull()
        // Nobody did this to you — you did, earlier. An actor here would make
        // the inbox read "Jij noemde jou".
        ->and($item->actor_id)->toBeNull();
});

it('does not deliver the same reminder twice', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->due()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    app(DeliverDueReminders::class)->handle();
    $second = app(DeliverDueReminders::class)->handle();

    expect($second)->toBe(['delivered' => 0, 'dropped' => 0])
        ->and(InboxItem::query()->count())->toBe(1)
        ->and(Reminder::query()->sole()->delivered_at)->not->toBeNull();
});

it('leaves a reminder alone until its moment', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
        'remind_at' => now()->addHour(),
    ]);

    expect(app(DeliverDueReminders::class)->handle())->toBe(['delivered' => 0, 'dropped' => 0])
        ->and(InboxItem::query()->count())->toBe(0);
});

it('drops a reminder whose message has been withdrawn', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->due()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    $message->delete();

    // Retired quietly rather than delivered: sending somebody to the space
    // where a message used to be is the reminder working exactly when it
    // should not.
    expect(app(DeliverDueReminders::class)->handle())->toBe(['delivered' => 0, 'dropped' => 1])
        ->and(InboxItem::query()->count())->toBe(0);
});

it('drops a reminder for a channel the member can no longer see', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->due()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    // Left the channel, and it is private — so there is nothing to go back to.
    $channel->update(['type' => ChannelType::Private]);
    $channel->members()->detach($user->id);

    expect(app(DeliverDueReminders::class)->handle())->toBe(['delivered' => 0, 'dropped' => 1])
        ->and(InboxItem::query()->count())->toBe(0);
});

it('moves an existing reminder rather than making a second', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => '20m',
    ])->assertRedirect();

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => '3h',
        'note' => 'Hier nog op antwoorden',
    ])->assertRedirect();

    $reminder = Reminder::query()->sole();

    expect($reminder->note)->toBe('Hier nog op antwoorden')
        ->and($reminder->remind_at->isAfter(now()->addHours(2)))->toBeTrue();
});

it('reads tomorrow and next week off the member own clock', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    // Half past midnight in Amsterdam: "morgenochtend" is a few hours away,
    // not one and a half days.
    Carbon::setTestNow(Carbon::parse('2027-03-03 23:30', 'Europe/Amsterdam')->utc());

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => 'tomorrow',
    ])->assertRedirect();

    $tomorrow = Reminder::query()->sole()->remind_at->setTimezone('Europe/Amsterdam');

    expect($tomorrow->format('Y-m-d H:i'))->toBe('2027-03-04 09:00');

    Reminder::query()->delete();

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => 'next_week',
    ])->assertRedirect();

    // The Monday, not "seven days from now" — somebody putting a thing off to
    // next week means the start of it.
    $nextWeek = Reminder::query()->sole()->remind_at->setTimezone('Europe/Amsterdam');

    expect($nextWeek->format('Y-m-d H:i'))->toBe('2027-03-08 09:00')
        ->and($nextWeek->dayOfWeek)->toBe(Carbon::MONDAY);

    Carbon::setTestNow();
});

it('refuses a moment nobody named', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    actingAs($user)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => 'over 400 jaar',
    ])->assertSessionHasErrors('when');

    expect(Reminder::query()->count())->toBe(0);
});

it('refuses a reminder on a message somebody may not read', function () {
    [, $workspace, $channel, $message] = reminderFixture();

    $channel->update(['type' => ChannelType::Private]);

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider);

    actingAs($outsider)->post(route('chat.messages.reminder', [$workspace, $channel, $message]), [
        'when' => '1h',
    ])->assertForbidden();

    expect(Reminder::query()->count())->toBe(0);
});

it('refuses a moment that has already passed', function () {
    [$user, , $channel, $message] = reminderFixture();

    expect(fn () => app(ScheduleReminder::class)->handle($user, $message, now()->subMinute()))
        ->toThrow(RuntimeException::class);
});

it('shows what is still waiting on the reminder tab', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    $reminder = Reminder::factory()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
        'note' => 'Hier nog op antwoorden',
        'remind_at' => now()->addHours(3),
    ]);

    actingAs($user)->get(route('chat.inbox.index', [$workspace, 'type' => 'reminder']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('pendingReminders', 1)
            ->where('pendingReminders.0.id', $reminder->id)
            ->where('pendingReminders.0.note', 'Hier nog op antwoorden')
            ->where('pendingReminders.0.messageId', $message->id)
            // Nothing in the list itself: it has not happened yet, and a future
            // row among things that have would be read as one of them.
            ->has('items', 0));
});

it('drops a reminder off the waiting list once it has gone off', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    Reminder::factory()->delivered()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    actingAs($user)->get(route('chat.inbox.index', [$workspace, 'type' => 'reminder']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('pendingReminders', 0));
});

it('keeps somebody else waiting reminders off your screen', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    Reminder::factory()->create([
        'user_id' => $other->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    // A reminder is a note to yourself about somebody else's sentence, and the
    // whole reason it is usable is that nobody else can see it.
    actingAs($user)->get(route('chat.inbox.index', [$workspace, 'type' => 'reminder']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('pendingReminders', 0));
});

it('lets somebody call their own reminder off, and nobody else theirs', function () {
    [$user, $workspace, $channel, $message] = reminderFixture();

    $reminder = Reminder::factory()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    $other = User::factory()->create();
    joinWorkspace($workspace, $other);

    // Whether a reminder exists is itself private, so this is a 404 rather
    // than a 403 — the answer must not confirm that the row is there.
    actingAs($other)->delete(route('chat.reminders.destroy', [$workspace, $reminder]))
        ->assertNotFound();

    actingAs($user)->delete(route('chat.reminders.destroy', [$workspace, $reminder]))
        ->assertRedirect();

    expect(Reminder::query()->count())->toBe(0);
});

it('lets a second reminder be set once the first has gone off', function () {
    [$user, , $channel, $message] = reminderFixture();

    Reminder::factory()->delivered()->create([
        'user_id' => $user->id,
        'message_id' => $message->id,
        'channel_id' => $channel->id,
    ]);

    // The unique index only holds the pending ones: wanting reminding again
    // tomorrow about the same message is an ordinary thing to want.
    app(ScheduleReminder::class)->handle($user, $message, now()->addDay());

    expect(Reminder::query()->count())->toBe(2)
        ->and(Reminder::pendingFor($user)->count())->toBe(1);
});
