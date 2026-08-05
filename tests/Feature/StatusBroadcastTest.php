<?php

use App\Enums\SystemRole;
use App\Events\StatusChanged;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

it('tells everyone in your channels that your status changed', function () {
    Event::fake([StatusChanged::class]);

    $me = User::factory()->create();
    $workspace = workspaceWithMember($me);
    $channel = channelWithMember($workspace, $me);

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($me)->patch(route('status.update'), [
        'status_emoji' => '☕',
        'status_text' => 'Koffie halen',
        'availability' => 'available',
    ])->assertRedirect();

    Event::assertDispatched(
        StatusChanged::class,
        fn (StatusChanged $event): bool => $event->recipientId === $colleague->id
            && $event->userId === $me->id
            && $event->text === 'Koffie halen'
    );

    // Their own menu shows it back to them, so they are told too.
    Event::assertDispatched(
        StatusChanged::class,
        fn (StatusChanged $event): bool => $event->recipientId === $me->id
    );
});

it('says nothing to a workspace member who shares no channel with you', function () {
    Event::fake([StatusChanged::class]);

    $me = User::factory()->create();
    $workspace = workspaceWithMember($me);
    channelWithMember($workspace, $me);

    $stranger = User::factory()->create();
    joinWorkspace($workspace, $stranger, SystemRole::Guest);

    actingAs($me)->patch(route('status.update'), [
        'status_text' => 'Koffie halen',
        'availability' => 'available',
    ])->assertRedirect();

    Event::assertNotDispatched(
        StatusChanged::class,
        fn (StatusChanged $event): bool => $event->recipientId === $stranger->id
    );
});

it('addresses each recipient on their own private channel', function () {
    Event::fake([StatusChanged::class]);

    $me = User::factory()->create();
    $workspace = workspaceWithMember($me);
    channelWithMember($workspace, $me);

    actingAs($me)->patch(route('status.update'), [
        'availability' => 'away',
    ])->assertRedirect();

    Event::assertDispatched(StatusChanged::class, function (StatusChanged $event) use ($me): bool {
        $channel = $event->broadcastOn()[0];

        return $channel instanceof PrivateChannel
            && $channel->name === 'private-App.Models.User.'.$me->id;
    });
});

it('carries the cleared status rather than leaving it out', function () {
    Event::fake([StatusChanged::class]);

    $me = User::factory()->create([
        'status_emoji' => '☕',
        'status_text' => 'Koffie halen',
    ]);
    $workspace = workspaceWithMember($me);
    channelWithMember($workspace, $me);

    actingAs($me)->patch(route('status.update'), [
        'availability' => 'available',
    ])->assertRedirect();

    // Null, not absent: the browser has to be able to tell "cleared" from
    // "unchanged", which is why the whole status travels every time.
    Event::assertDispatched(StatusChanged::class, function (StatusChanged $event): bool {
        $payload = $event->broadcastWith();

        return $payload['emoji'] === null && $payload['text'] === null;
    });
});
