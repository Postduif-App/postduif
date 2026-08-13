<?php

use App\Actions\Tickets\CommentOnTicket;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\TicketStatus;
use App\Features\Tickets as TicketsFeature;
use App\Mail\TicketReplyMail;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMailSettings;
use App\Support\InboundEmail;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * A workspace with a letterbox: tickets switched on, a channel that keeps them,
 * and a delivery token.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: WorkspaceMailSettings}
 */
function inboundMailFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    Feature::for($workspace)->activate(TicketsFeature::class);

    // Tickets are off on a fresh channel, so the letterbox would have nowhere
    // to put anything — the same check ReceiveInboundEmail makes at delivery.
    $channel = channelWithMember($workspace, $user);
    $channel->forceFill(['ticket_policy' => ChannelTicketPolicy::Everyone])->save();

    $settings = $workspace->mailSettings()->firstOrNew();
    $settings->inbound_channel_id = $channel->id;
    $settings->inbound_address = 'support@acme.test';
    $settings->regenerateInboundToken();
    $workspace->mailSettings()->save($settings);

    return [$user, $workspace, $channel, $settings];
}

/**
 * A delivery in Postmark's shape, which is the one with the most awkward field
 * names — anything that reads this reads the plainer ones too.
 *
 * @return array<string, mixed>
 */
function postmarkDelivery(array $overrides = []): array
{
    return [
        'FromFull' => ['Email' => 'klant@example.test', 'Name' => 'Jan Klant'],
        'ToFull' => [['Email' => 'support@acme.test']],
        'Subject' => 'De printer doet het niet',
        'TextBody' => "Hij knippert oranje.\n\nGroet, Jan",
        'MessageID' => 'abc-123@example.test',
        ...$overrides,
    ];
}

it('opens a ticket from an e-mail nobody has seen before', function () {
    [, $workspace, $channel, $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())
        ->assertOk()
        ->assertJsonPath('handled', true);

    $ticket = Ticket::query()->sole();

    expect($ticket->channel_id)->toBe($channel->id)
        ->and($ticket->workspace_id)->toBe($workspace->id)
        ->and($ticket->title)->toBe('De printer doet het niet')
        ->and($ticket->body)->toBe("Hij knippert oranje.\n\nGroet, Jan")
        ->and($ticket->status)->toBe(TicketStatus::Open)
        // Nobody inside opened this, and the row says so in both directions.
        ->and($ticket->opened_by)->toBeNull()
        ->and($ticket->sender_email)->toBe('klant@example.test')
        ->and($ticket->openedByName())->toBe('Jan Klant');
});

it('lands a reply on the ticket the address points at', function () {
    [, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    $ticket = Ticket::query()->sole();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'Subject' => 'Re: De printer doet het niet',
        'TextBody' => 'Hij doet het weer.',
        'MessageID' => 'def-456@example.test',
        'ToFull' => [['Email' => 'support+t'.$ticket->number.'@acme.test']],
    ]))->assertOk();

    expect(Ticket::query()->count())->toBe(1)
        ->and(TicketComment::query()->count())->toBe(1);

    $comment = TicketComment::query()->sole();

    expect($comment->ticket_id)->toBe($ticket->id)
        ->and($comment->body)->toBe('Hij doet het weer.')
        ->and($comment->user_id)->toBeNull()
        ->and($comment->sender_email)->toBe('klant@example.test');
});

it('finds the ticket through the reply headers when the address carries no tag', function () {
    [, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    // Written to the plain address, the way a client that drops plus-addressing
    // would send it. The In-Reply-To header is then the only thread there is.
    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'TextBody' => 'Nog steeds stuk.',
        'MessageID' => 'ghi-789@example.test',
        'headers' => ['in-reply-to' => '<abc-123@example.test>'],
    ]))->assertOk();

    expect(Ticket::query()->count())->toBe(1)
        ->and(TicketComment::query()->sole()->body)->toBe('Nog steeds stuk.');
});

it('reopens a closed ticket when the sender writes again', function () {
    [, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    $ticket = Ticket::query()->sole();
    $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now()])->save();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'MessageID' => 'jkl-000@example.test',
        'ToFull' => [['Email' => 'support+t'.$ticket->number.'@acme.test']],
    ]))->assertOk();

    $ticket->refresh();

    // A customer writing again means the work is not finished, whatever the
    // queue thought — and the closing date goes with it, or every report that
    // reads that column alone still counts this as done.
    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)->toBeNull();
});

it('will not let a reply tag reach another workspace ticket', function () {
    [, , , $settings] = inboundMailFixture();

    // Somebody else's ticket, with a number this delivery is about to name.
    [, , , $otherSettings] = inboundMailFixture();
    postJson(route('mail.inbound', $otherSettings->inbound_token), postmarkDelivery())->assertOk();

    $theirs = Ticket::query()->sole();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'MessageID' => 'mno-111@example.test',
        'ToFull' => [['Email' => 'support+t'.$theirs->number.'@acme.test']],
    ]))->assertOk();

    // A new ticket in the workspace the mail was actually sent to, and not a
    // sentence appended to a stranger's.
    expect($theirs->comments()->count())->toBe(0)
        ->and(Ticket::query()->count())->toBe(2);
});

it('answers 404 for a token nobody was ever given', function () {
    inboundMailFixture();

    postJson(route('mail.inbound', 'niet-een-echt-token'), postmarkDelivery())
        ->assertNotFound();

    expect(Ticket::query()->count())->toBe(0);
});

it('accepts and drops mail for a workspace that has since switched tickets off', function () {
    [, $workspace, , $settings] = inboundMailFixture();

    Feature::for($workspace)->deactivate(TicketsFeature::class);

    // Still 200: a provider reads anything else as "retry", and this will be
    // just as unwanted in four hours.
    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())
        ->assertOk()
        ->assertJsonPath('handled', false);

    expect(Ticket::query()->count())->toBe(0);
});

it('reads the plainer provider shapes as well as the awkward one', function () {
    [, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), [
        'from' => 'Jan Klant <klant@example.test>',
        'to' => 'support@acme.test',
        'subject' => 'Vraag',
        'text' => 'Hoe werkt dit?',
        'message_id' => 'pqr-222@example.test',
    ])->assertOk();

    $ticket = Ticket::query()->sole();

    expect($ticket->sender_email)->toBe('klant@example.test')
        ->and($ticket->sender_name)->toBe('Jan Klant')
        ->and($ticket->title)->toBe('Vraag');
});

it('cuts the quoted original off a reply and keeps what was written', function () {
    $email = InboundEmail::fromPayload([
        'from' => 'klant@example.test',
        'subject' => 'Re: Re: Fwd: De printer',
        'text' => "Dat werkte, dank!\n\nOp 3 maart 2027 schreef Support:\n> Heb je hem al uit en aan gezet?",
    ]);

    expect($email->body)->toBe('Dat werkte, dank!')
        // Every reply prefix off, and once — a queue where half the rows begin
        // "Re: Re: Fwd:" reads as a mailbox rather than as work.
        ->and($email->ticketTitle())->toBe('De printer');
});

it('names an unaddressed mail rather than opening a ticket with no title', function () {
    $email = InboundEmail::fromPayload([
        'from' => 'klant@example.test',
        'text' => 'Geen onderwerp meegegeven.',
    ]);

    expect($email->ticketTitle())->toBe(__('mail.inbound.no_subject'));
});

it('lets a beheerder point the letterbox at a channel and roll the secret', function () {
    [$user, $workspace, $channel] = inboundMailFixture();

    actingAs($user)->patch(route('workspace.mail.inbound'), [
        'inbound_channel_id' => $channel->id,
        'inbound_address' => 'post@acme.test',
    ])->assertRedirect();

    expect($workspace->mailSettings()->sole()->inbound_address)->toBe('post@acme.test');

    $before = $workspace->mailSettings()->sole()->inbound_token;

    actingAs($user)->post(route('workspace.mail.inbound.token'))->assertRedirect();

    expect($workspace->mailSettings()->sole()->inbound_token)->not->toBe($before);
});

it('refuses a channel from another workspace as the letterbox', function () {
    [$user] = inboundMailFixture();

    $elsewhere = Channel::factory()->create();

    actingAs($user)->patch(route('workspace.mail.inbound'), [
        'inbound_channel_id' => $elsewhere->id,
    ])->assertSessionHasErrors('inbound_channel_id');
});

/*
 * The way back. Everything above is post coming in; a workspace that can only
 * receive is a one-way street — the person who wrote in sees nothing of what
 * was answered and writes again to ask.
 */
it('mails a colleague his answer back to whoever wrote in', function () {
    Mail::fake();

    [$user, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    $ticket = Ticket::query()->sole();

    $comment = app(CommentOnTicket::class)->handle($ticket, $user, 'Zet hem eens uit en aan.');

    Mail::assertSent(TicketReplyMail::class, function (TicketReplyMail $mail) use ($ticket, $comment): bool {
        return $mail->hasTo('klant@example.test')
            // The tagged address, which is the one thing every mail client
            // copies back without understanding it.
            && $mail->replyAddress === "support+t{$ticket->number}@acme.test"
            // And the id this mail went out under, kept on the comment so a
            // reply quoting it finds the ticket again.
            && $mail->messageId === $comment->fresh()->mail_message_id;
    });
});

it('finds the ticket back from a reply that only quotes the message id', function () {
    Mail::fake();

    [$user, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    $ticket = Ticket::query()->sole();
    $comment = app(CommentOnTicket::class)->handle($ticket, $user, 'Zet hem eens uit en aan.');

    /*
     * Answered to the plain address rather than to the tagged one — what
     * happens the moment somebody forwards the mail and a colleague replies
     * from their own client. The tag is gone; the References header is not.
     */
    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'ToFull' => [['Email' => 'support@acme.test']],
        'Subject' => 'Re: De printer doet het niet',
        'TextBody' => 'Dat werkte, dank!',
        'MessageID' => 'zzz-999@example.test',
        'Headers' => [[
            'Name' => 'References',
            'Value' => '<'.$comment->fresh()->mail_message_id.'>',
        ]],
    ]))->assertOk();

    expect(TicketComment::query()->where('ticket_id', $ticket->id)->count())->toBe(2);
});

it('says nothing back for a ticket that was raised on the board', function () {
    Mail::fake();

    [$user, $workspace, $channel] = inboundMailFixture();

    $ticket = Ticket::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'opened_by' => $user->id,
    ]);

    app(CommentOnTicket::class)->handle($ticket, $user, 'Ik pak hem op.');

    // Nobody wrote in, so there is nobody to write back to. A mail here would
    // go to whoever the fixture happens to have in it.
    Mail::assertNothingSent();
});

it('does not mail a customer his own words back to him', function () {
    [$user, , , $settings] = inboundMailFixture();

    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery())->assertOk();

    Mail::fake();

    // A second delivery on the same ticket: a comment with no member behind it,
    // and answering it would be a loop with the customer's own sentence in it.
    postJson(route('mail.inbound', $settings->inbound_token), postmarkDelivery([
        'Subject' => 'Re: De printer doet het niet',
        'TextBody' => 'Nog steeds kapot.',
        'MessageID' => 'def-456@example.test',
    ]))->assertOk();

    Mail::assertNothingSent();
});
