<?php

use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Jobs\SyncTicketToBacklogJob;
use App\Models\BacklogConnection;
use App\Models\Channel;
use App\Models\Ticket;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\postJson;

/**
 * The Backlog integration: a signed webhook in, a mapped ticket in the
 * database, an authenticated call back out, and a connection that switches
 * itself off once it stops being answered.
 */

/**
 * A connection with a channel to receive into, its webhook secret in the
 * clear (returned alongside, since only the encrypted form is ever readable
 * off the model itself).
 *
 * @param  array<string, mixed>  $state
 * @return array{0: BacklogConnection, 1: string}
 */
function backlogConnectionWithSecret(array $state = []): array
{
    $channel = Channel::factory()->create();

    $connection = BacklogConnection::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        ...$state,
    ]);

    return [$connection, $connection->webhook_secret];
}

/**
 * @param  array<string, mixed>  $issue
 * @return array{0: array<string, mixed>, 1: string, 2: string}
 */
function signedIssuePayload(string $secret, array $issue = [], ?int $timestamp = null): array
{
    $payload = [
        'id' => 'ISS-1',
        'title' => 'De export knop doet niets',
        'description' => 'Klikken geeft geen resultaat.',
        'status' => ['category' => 'started'],
        'priority' => 2,
        'url' => 'https://backlog.example.com/issues/ISS-1',
        ...$issue,
    ];

    $timestamp ??= time();
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return [$payload, $signature, (string) $timestamp];
}

// --- 1. Inbound webhook rejects an invalid/stale signature ------------------

it('rejects an inbound webhook with a signature that does not match', function () {
    [$connection, $secret] = backlogConnectionWithSecret();
    [$payload, , $timestamp] = signedIssuePayload($secret);

    postJson(route('webhooks.backlog.store', $connection), $payload, [
        'X-Backlog-Signature' => 'sha256=not-the-right-hash',
        'X-Backlog-Delivery-Timestamp' => $timestamp,
        'X-Backlog-Event' => 'issue.created',
    ])->assertStatus(401);

    expect(Ticket::query()->count())->toBe(0);
});

/**
 * A bare 401 says nothing about which check failed, and a beheerder staring
 * at one from outside the app has no way to tell "wrong secret" from "clock
 * skew" from "endpoint points at the wrong connection" without this.
 */
it('logs why a delivery was rejected, without the secret or the signature', function () {
    Log::spy();

    [$connection, $secret] = backlogConnectionWithSecret();
    [$payload, , $timestamp] = signedIssuePayload($secret);

    postJson(route('webhooks.backlog.store', $connection), $payload, [
        'X-Backlog-Signature' => 'sha256=not-the-right-hash',
        'X-Backlog-Delivery-Timestamp' => $timestamp,
        'X-Backlog-Event' => 'issue.created',
    ])->assertStatus(401);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Backlog webhook delivery rejected'
            && $context['connection_id'] === $connection->id
            && str_contains($context['reason'], 'does not match')
            && ! str_contains(json_encode($context), $secret)
    );
});

it('rejects an inbound webhook whose timestamp has fallen outside the replay window', function () {
    [$connection, $secret] = backlogConnectionWithSecret();
    [$payload, $signature, $timestamp] = signedIssuePayload($secret, timestamp: time() - 600);

    postJson(route('webhooks.backlog.store', $connection), $payload, [
        'X-Backlog-Signature' => $signature,
        'X-Backlog-Delivery-Timestamp' => $timestamp,
        'X-Backlog-Event' => 'issue.created',
    ])->assertStatus(401);
});

it('accepts an inbound webhook whose signature and timestamp are valid', function () {
    [$connection, $secret] = backlogConnectionWithSecret();
    [$payload, $signature, $timestamp] = signedIssuePayload($secret);

    postJson(route('webhooks.backlog.store', $connection), $payload, [
        'X-Backlog-Signature' => $signature,
        'X-Backlog-Delivery-Timestamp' => $timestamp,
        'X-Backlog-Event' => 'issue.created',
    ])->assertStatus(202);

    expect(Ticket::query()->where('external_id', 'ISS-1')->count())->toBe(1);
});

// --- 2. A redelivered payload does not create a duplicate ticket -----------

it('does not create a duplicate ticket when the same webhook delivery is processed twice', function () {
    [$connection, $secret] = backlogConnectionWithSecret();
    [$payload, $signature, $timestamp] = signedIssuePayload($secret);

    $headers = [
        'X-Backlog-Signature' => $signature,
        'X-Backlog-Delivery-Timestamp' => $timestamp,
        'X-Backlog-Event' => 'issue.created',
    ];

    postJson(route('webhooks.backlog.store', $connection), $payload, $headers)->assertStatus(202);
    postJson(route('webhooks.backlog.store', $connection), $payload, $headers)->assertStatus(202);

    $tickets = Ticket::query()->where('external_source', 'backlog')->where('external_id', 'ISS-1')->get();

    expect($tickets)->toHaveCount(1);
    expect($tickets->first()->title)->toBe('De export knop doet niets');
});

// --- 3. SyncTicketToBacklogJob sends the expected authenticated request ----

it('PATCHes the mapped issue with a bearer token when a linked ticket changes status', function () {
    Http::fake([
        '*/api/v1/issues/*' => Http::response(['id' => 'ISS-1'], 200),
    ]);

    $channel = Channel::factory()->create();

    $connection = BacklogConnection::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'backlog_url' => 'https://93.184.216.34',
        // Cached already, so the job never has to authenticate first.
        'access_token' => 'a-cached-token',
        'access_token_expires_at' => now()->addHour(),
    ]);

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'external_source' => 'backlog',
        'external_id' => 'ISS-1',
    ]);
    $ticket->forceFill(['status' => TicketStatus::Resolved])->save();

    SyncTicketToBacklogJob::dispatchSync($ticket->id, SyncTicketToBacklogJob::ACTION_STATUS);

    Http::assertSent(function (ClientRequest $request) {
        return $request->url() === 'https://93.184.216.34/api/v1/issues/ISS-1'
            && $request->method() === 'PATCH'
            && $request->hasHeader('Authorization', 'Bearer a-cached-token')
            && $request['status'] === 'completed';
    });

    expect($connection->fresh()->consecutive_failures)->toBe(0);
});

it('PATCHes the mapped issue with a Backlog priority when a linked ticket changes priority', function () {
    Http::fake([
        '*/api/v1/issues/*' => Http::response(['id' => 'ISS-1'], 200),
    ]);

    $channel = Channel::factory()->create();

    $connection = BacklogConnection::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'backlog_url' => 'https://93.184.216.34',
        'access_token' => 'a-cached-token',
        'access_token_expires_at' => now()->addHour(),
    ]);

    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'external_source' => 'backlog',
        'external_id' => 'ISS-1',
    ]);
    $ticket->forceFill(['priority' => TicketPriority::Urgent])->save();

    SyncTicketToBacklogJob::dispatchSync($ticket->id, SyncTicketToBacklogJob::ACTION_PRIORITY);

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://93.184.216.34/api/v1/issues/ISS-1'
        && $request->method() === 'PATCH'
        && $request['priority'] === 1);

    expect($connection->fresh()->consecutive_failures)->toBe(0);
});

it('queues an outbound priority sync when a mirrored ticket changes priority', function () {
    Bus::fake();

    $channel = Channel::factory()->create();
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'external_source' => 'backlog',
        'external_id' => 'ISS-1',
        'priority' => TicketPriority::Normal,
    ]);

    app(UpdateTicket::class)->priority($ticket, TicketPriority::Urgent);

    Bus::assertDispatched(
        SyncTicketToBacklogJob::class,
        fn (SyncTicketToBacklogJob $job) => $job->ticketId === $ticket->id
            && $job->action === SyncTicketToBacklogJob::ACTION_PRIORITY,
    );
});

it('does not queue a sync for a priority change on a ticket that never mirrored Backlog', function () {
    Bus::fake();

    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Normal]);

    app(UpdateTicket::class)->priority($ticket, TicketPriority::Urgent);

    Bus::assertNotDispatched(SyncTicketToBacklogJob::class);
});

it('does nothing when the ticket has no linked Backlog issue', function () {
    Http::fake();

    $ticket = Ticket::factory()->create();

    SyncTicketToBacklogJob::dispatchSync($ticket->id, SyncTicketToBacklogJob::ACTION_STATUS);

    Http::assertNothingSent();
});

// --- 4. A connection disables itself past the failure limit -----------------

it('switches itself off once consecutive failures pass the limit', function () {
    $connection = BacklogConnection::factory()->create(['consecutive_failures' => 0, 'is_active' => true]);

    for ($i = 0; $i < BacklogConnection::FAILURE_LIMIT - 1; $i++) {
        $connection->recordFailure();
    }

    expect($connection->fresh()->is_active)->toBeTrue()
        ->and($connection->fresh()->isHealthy())->toBeTrue();

    $connection->recordFailure();

    expect($connection->fresh()->is_active)->toBeFalse()
        ->and($connection->fresh()->isHealthy())->toBeFalse();
});

it('resets the failure streak on the next success', function () {
    $connection = BacklogConnection::factory()->create(['consecutive_failures' => 3]);

    $connection->recordSuccess();

    $connection = $connection->fresh();

    expect($connection->consecutive_failures)->toBe(0);
    expect($connection->is_active)->toBeTrue();
});
