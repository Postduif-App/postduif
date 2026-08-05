<?php

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\Workspace;

test('ticketnummers lopen op binnen een workspace', function () {
    $workspace = Workspace::factory()->create();
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    $numbers = collect(range(1, 3))
        ->map(fn () => Ticket::factory()->create(['channel_id' => $channel->id])->number);

    expect($numbers->all())->toBe([1, 2, 3]);
});

test('twee workspaces tellen los van elkaar', function () {
    $one = Channel::factory()->create();
    $other = Channel::factory()->create();

    $first = Ticket::factory()->create(['channel_id' => $one->id]);
    $second = Ticket::factory()->create(['channel_id' => $other->id]);

    expect($first->number)->toBe(1)
        ->and($second->number)->toBe(1)
        ->and($first->workspace_id)->not->toBe($second->workspace_id);
});

test('een verwijderd ticket geeft zijn nummer niet door', function () {
    $channel = Channel::factory()->create();

    Ticket::factory()->create(['channel_id' => $channel->id])->delete();

    expect(Ticket::factory()->create(['channel_id' => $channel->id])->number)->toBe(2);
});

test('open telt alles waar de klant nog op wacht', function () {
    $channel = Channel::factory()->create();

    foreach (TicketStatus::cases() as $status) {
        Ticket::factory()->status($status)->create(['channel_id' => $channel->id]);
    }

    expect($channel->tickets()->open()->pluck('status')->all())->toEqualCanonicalizing([
        TicketStatus::Open,
        TicketStatus::InProgress,
        TicketStatus::Waiting,
    ]);
});

test('het bord zet de urgentste bovenaan', function () {
    $channel = Channel::factory()->create();

    $normal = Ticket::factory()->priority(TicketPriority::Normal)->create(['channel_id' => $channel->id]);
    $urgent = Ticket::factory()->priority(TicketPriority::Urgent)->create(['channel_id' => $channel->id]);
    $low = Ticket::factory()->priority(TicketPriority::Low)->create(['channel_id' => $channel->id]);

    expect($channel->tickets()->inBoardOrder()->pluck('id')->all())
        ->toBe([$urgent->id, $normal->id, $low->id]);
});

test('een gast ziet alleen tickets uit zijn eigen kanalen', function () {
    $guest = User::factory()->create();
    $workspace = workspaceWithMember($guest, SystemRole::Guest);

    $shared = channelWithMember($workspace, $guest);
    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
    ]);

    $visible = Ticket::factory()->create(['channel_id' => $shared->id]);
    Ticket::factory()->create(['channel_id' => $elsewhere->id]);

    expect(Ticket::query()->visibleTo($guest)->pluck('id')->all())->toBe([$visible->id]);
});

test('een gepromoveerd ticket blijft weten waar het vandaan kwam', function () {
    $message = Message::factory()->create();

    $ticket = Ticket::factory()->promotedFrom($message)->create();
    $message->delete();

    expect($ticket->fresh()->sourceMessage)->not->toBeNull()
        ->and($ticket->channel_id)->toBe($message->channel_id);
});

test('een gebeurtenis kent geen updated_at', function () {
    $event = TicketEvent::factory()->statusChange(TicketStatus::Open, TicketStatus::Waiting)->create();

    expect($event->refresh()->payload)->toBe(['from' => 'open', 'to' => 'waiting'])
        ->and(TicketEvent::UPDATED_AT)->toBeNull();
});
