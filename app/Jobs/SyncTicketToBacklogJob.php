<?php

namespace App\Jobs;

use App\Models\BacklogConnection;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Support\BacklogIssueMapper;
use App\Workflows\GuardOutboundUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tell Backlog about one change made to one ticket on this side.
 *
 * The outward twin of ProcessBacklogWebhookJob, and its structure mirrors
 * DeliverContractWebhookJob for the same reasons that one is built the way it
 * is: one job per delivery, its own queue so a slow far end never holds up the
 * request that made the change, and the address and credential looked up fresh
 * at send time rather than carried in the payload, so a connection that was
 * switched off while this sat queued is honoured.
 *
 * Only ever dispatched for a ticket that isExternal() — one this workspace
 * already mirrors from Backlog. A ticket opened here that never came from
 * Backlog has nothing at the other end to PATCH.
 */
class SyncTicketToBacklogJob implements ShouldQueue
{
    use Queueable;

    /** @see DeliverContractWebhookJob — the same budget, the same reasoning. */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    private const TIMEOUT = 5;

    private const CONNECT_TIMEOUT = 3;

    public const ACTION_STATUS = 'status';

    public const ACTION_COMMENT = 'comment';

    /**
     * @param  string  $action  One of self::ACTION_STATUS, self::ACTION_COMMENT.
     * @param  int|null  $commentId  Set only for ACTION_COMMENT.
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly string $action,
        public readonly ?int $commentId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(GuardOutboundUrl $guard): void
    {
        $ticket = Ticket::query()->find($this->ticketId);

        if ($ticket === null || ! $ticket->isExternal()) {
            return;
        }

        $connection = BacklogConnection::query()
            ->where('workspace_id', $ticket->workspace_id)
            ->where('channel_id', $ticket->channel_id)
            ->active()
            ->first();

        if ($connection === null) {
            return;
        }

        $token = $connection->accessToken();

        if ($token === null) {
            $connection->recordFailure();

            return;
        }

        try {
            $baseUrl = $guard->handle(rtrim($connection->backlog_url, '/'));
        } catch (RuntimeException $exception) {
            // The configured address is not one we will call — a private
            // address will still be private on the next attempt, so this is
            // not something retrying fixes. Recorded, not thrown: throwing
            // would only earn three more identical refusals.
            $connection->recordFailure();

            return;
        }

        try {
            $response = $this->action === self::ACTION_COMMENT
                ? $this->postComment($token, $baseUrl, $ticket)
                : $this->patchStatus($token, $baseUrl, $ticket);
        } catch (ConnectionException $exception) {
            $connection->recordFailure();

            throw $exception;
        }

        if ($response === null) {
            // The comment this was queued for is gone (withdrawn, or its
            // ticket deleted from under it) — nothing left to tell Backlog.
            return;
        }

        if (! $response->successful()) {
            $connection->recordFailure();

            throw new RuntimeException(
                "Backlog refused syncing ticket {$ticket->id} ({$this->action}) with status {$response->status()}."
            );
        }

        $connection->recordSuccess();
    }

    private function patchStatus(string $token, string $baseUrl, Ticket $ticket): Response
    {
        return Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withoutRedirecting()
            ->patch("{$baseUrl}/api/v1/issues/{$ticket->external_id}", [
                'status' => BacklogIssueMapper::toBacklogCategory($ticket->status),
            ]);
    }

    private function postComment(string $token, string $baseUrl, Ticket $ticket): ?Response
    {
        $comment = $this->commentId === null ? null : TicketComment::query()->find($this->commentId);

        if ($comment === null || $comment->ticket_id !== $ticket->id) {
            return null;
        }

        return Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->withoutRedirecting()
            ->post("{$baseUrl}/api/v1/issues/{$ticket->external_id}/comments", [
                'body' => $comment->body,
            ]);
    }
}
