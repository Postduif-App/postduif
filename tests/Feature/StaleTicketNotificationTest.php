<?php

use App\Actions\Tickets\FindStaleTickets;
use App\Enums\Availability;
use App\Enums\SystemRole;
use App\Enums\TicketStatus;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\TicketNeedsAttention;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Notification::fake();
});

it('nags about a ticket nobody answered', function () {
    [$member, , , $channel] = ticketFixture();

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'created_at' => now()->subHours(FindStaleTickets::SILENCE_HOURS + 1),
    ]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertSentTo(
        $member,
        fn (TicketNeedsAttention $notification) => $notification->tickets
            ->pluck('id')
            ->contains($ticket->id)
    );

    expect($ticket->fresh()->reminded_at)->not->toBeNull();
});

it('leaves a fresh ticket alone', function () {
    [$member, , , $channel] = ticketFixture();

    Ticket::factory()->create(['channel_id' => $channel->id]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertNothingSent();
});

it('leaves a ticket alone that waits on the customer', function () {
    [$member, , , $channel] = ticketFixture();

    Ticket::factory()->status(TicketStatus::Waiting)->create([
        'channel_id' => $channel->id,
        'created_at' => now()->subHours(FindStaleTickets::SILENCE_HOURS + 1),
    ]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertNothingSent();
});

it('nags only the assignee once a ticket has one', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    $colleague = User::factory()->create(['notify_via_mail' => true]);
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    Ticket::factory()->overdue()->create([
        'channel_id' => $channel->id,
        'assigned_to' => $colleague->id,
    ]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertSentTo($colleague, TicketNeedsAttention::class);
    Notification::assertNotSentTo($member, TicketNeedsAttention::class);
});

it('does not tell the customer their own ticket is being ignored', function () {
    [$member, $guest, , $channel] = ticketFixture();

    Ticket::factory()->overdue()->create([
        'channel_id' => $channel->id,
        'opened_by' => $guest->id,
    ]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertSentTo($member, TicketNeedsAttention::class);
    Notification::assertNotSentTo($guest, TicketNeedsAttention::class);
});

it('says nothing twice within the cooldown', function () {
    [$member, , , $channel] = ticketFixture();

    Ticket::factory()->overdue()->create(['channel_id' => $channel->id]);

    artisan('tickets:notify-stale')->assertSuccessful();
    Notification::assertSentToTimes($member, TicketNeedsAttention::class, 1);

    artisan('tickets:notify-stale')->assertSuccessful();
    Notification::assertSentToTimes($member, TicketNeedsAttention::class, 1);
});

it('nags a member who left only their browser switched on', function () {
    [$member, , , $channel] = ticketFixture();
    $member->forceFill(['notify_via_mail' => false, 'notify_via_push' => true])->save();
    PushSubscription::factory()->for($member)->create();

    $ticket = Ticket::factory()->overdue()->create(['channel_id' => $channel->id]);

    artisan('tickets:notify-stale')->assertSuccessful();

    /*
     * A browser counts as somewhere for the reminder to arrive. Without that,
     * somebody who turned their mail off and their browser on would be judged
     * unreachable and never nagged about anything again.
     */
    Notification::assertSentTo(
        $member,
        TicketNeedsAttention::class,
        function (TicketNeedsAttention $notification, array $channels) use ($member, $ticket, $channel): bool {
            expect($channels)->toBe([WebPushChannel::class]);

            $message = $notification->toWebPush($member);

            // One bubble per workspace, replaced by the next run rather than
            // stacked beside it.
            expect($message->tag)->toBe('workspace-tickets-'.$channel->workspace_id)
                ->and($message->url)->toBe(route('chat.show', [
                    $channel->workspace,
                    $channel,
                    'view' => 'tickets',
                    'ticket' => $ticket->number,
                ]))
                ->and($message->body)->toContain('#'.$ticket->number);

            return true;
        },
    );
});

it('respects do not disturb', function () {
    [$member, , , $channel] = ticketFixture();
    // forceFill, because availability is not fillable: it is set through
    // SetStatus rather than through a form post.
    $member->forceFill(['availability' => Availability::DoNotDisturb])->save();

    Ticket::factory()->overdue()->create(['channel_id' => $channel->id]);

    artisan('tickets:notify-stale')->assertSuccessful();

    Notification::assertNothingSent();
});
