<?php

namespace App\Jobs;

use App\Actions\Tickets\AnnounceTicket;
use App\Actions\Tickets\RecordTicketEvent;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\BacklogConnection;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Support\BacklogIssueMapper;
use App\Support\BacklogSyncGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Apply one Backlog webhook delivery to this workspace's tickets.
 *
 * Its own job rather than work done in the controller, for the reason every
 * inbound webhook here is: BacklogWebhookController's whole job is to decide
 * whether to believe the delivery, and Backlog is owed an answer inside
 * seconds regardless of how long finding-or-creating a ticket takes.
 *
 * Keyed by (external_source='backlog', external_id) — see Ticket::scopeMirroring
 * — which is what makes a redelivered `issue.created` idempotent: Backlog
 * retries a delivery it never heard an answer to, and the second attempt must
 * find the ticket the first one made rather than mint a duplicate.
 */
class ProcessBacklogWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Three goes. Shorter than the outbound webhook's four — nothing about
     * applying a payload to our own database gets better with a fourth
     * attempt if the third one failed for a reason that will still be true a
     * few minutes later.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public const SOURCE = 'backlog';

    /**
     * @param  array<string, mixed>  $payload  The delivery's JSON body,
     *                                         decoded — the IssueResource (or
     *                                         comment/project-update
     *                                         equivalent) itself, per
     *                                         Backlog's webhook contract.
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $event,
        public readonly array $payload,
    ) {
        // Its own queue, the same reasoning DeliverContractWebhookJob gives
        // for the outbound side: this is triggered by the open internet, and
        // a slow delivery must never hold up a member's own request.
        $this->onQueue('webhooks');
    }

    public function handle(
        RecordTicketEvent $recordTicketEvent,
        UpdateTicket $updateTicket,
        AnnounceTicket $announceTicket,
    ): void {
        $connection = BacklogConnection::query()->find($this->connectionId);

        if ($connection === null || ! $connection->is_active) {
            // Deleted or switched off between the controller accepting this
            // delivery and the worker picking it up. Silence is correct: an
            // administrator turned this off on purpose.
            return;
        }

        try {
            match ($this->event) {
                BacklogConnection::EVENT_ISSUE_CREATED,
                BacklogConnection::EVENT_ISSUE_UPDATED => $this->syncIssue(
                    $connection, $recordTicketEvent, $updateTicket, $announceTicket,
                ),
                BacklogConnection::EVENT_ISSUE_DELETED => $this->closeIssue($updateTicket),
                BacklogConnection::EVENT_ISSUE_COMMENTED => $this->syncComment(),
                // project_update.published has no ticket to apply to yet —
                // accepted and ignored rather than treated as a failure.
                default => null,
            };
        } catch (RuntimeException $exception) {
            /*
             * Not recorded here — see failed(). A malformed payload fails
             * every one of its three attempts identically, and counting each
             * of those against the connection would trip FAILURE_LIMIT after
             * three or four bad deliveries instead of ten: one delivery, one
             * failure, recorded once the retries are exhausted.
             */
            throw $exception;
        }

        $connection->recordSuccess();
    }

    /**
     * Create or update the ticket this issue maps to.
     */
    private function syncIssue(
        BacklogConnection $connection,
        RecordTicketEvent $recordTicketEvent,
        UpdateTicket $updateTicket,
        AnnounceTicket $announceTicket,
    ): void {
        $externalId = $this->externalIssueId();
        $title = (string) ($this->payload['title'] ?? 'Untitled issue');
        $body = (string) ($this->payload['description'] ?? $this->payload['body'] ?? '');
        $url = $this->payload['url'] ?? null;
        $status = BacklogIssueMapper::toTicketStatus($this->payload['status'] ?? null);
        $priority = BacklogIssueMapper::toTicketPriority($this->payload['priority'] ?? null);

        DB::transaction(function () use (
            $connection, $externalId, $title, $body, $url, $status, $priority,
            $recordTicketEvent, $updateTicket, $announceTicket,
        ) {
            $ticket = Ticket::query()->mirroring(self::SOURCE, $externalId)->lockForUpdate()->first();

            if ($ticket === null) {
                $ticket = Ticket::create([
                    'workspace_id' => $connection->workspace_id,
                    'channel_id' => $connection->channel_id,
                    'number' => $connection->workspace->claimTicketNumber(),
                    'title' => $title,
                    'body' => $body,
                    'priority' => $priority,
                    'external_source' => self::SOURCE,
                    'external_id' => $externalId,
                    'external_url' => is_string($url) ? $url : null,
                ]);

                $recordTicketEvent->handle($ticket, TicketEventType::Created, null, ['source' => self::SOURCE]);
                $announceTicket->opened($ticket);
                TicketUpdated::dispatch($ticket);

                return;
            }

            // Marked before either write below: whichever of them actually
            // changes something is the one that would otherwise bounce
            // straight back out — see BacklogSyncGuard.
            BacklogSyncGuard::markEchoed($ticket->id);

            if ($ticket->status !== $status) {
                $updateTicket->status($ticket, $status, null);
            }

            if ($ticket->priority !== $priority) {
                $updateTicket->priority($ticket, $priority, null);
            }

            $ticket->forceFill([
                'title' => $title,
                'body' => $body,
                'external_url' => is_string($url) ? $url : $ticket->external_url,
            ])->save();

            TicketUpdated::dispatch($ticket);
        });
    }

    /**
     * Close the mapped ticket rather than deleting it.
     *
     * A ticket is this workspace's own record of the work, including whatever
     * was said about it in the channel — deleting it because the far end
     * removed its issue would throw that conversation away over a decision
     * that was Backlog's to make, not this workspace's.
     */
    private function closeIssue(UpdateTicket $updateTicket): void
    {
        $ticket = Ticket::query()->mirroring(self::SOURCE, $this->externalIssueId())->first();

        if ($ticket === null) {
            return;
        }

        BacklogSyncGuard::markEchoed($ticket->id);

        $updateTicket->status($ticket, TicketStatus::Closed, null);
    }

    /**
     * Mirror a Backlog comment onto the ticket's timeline.
     *
     * Written the same way mail-authored comments are — no user_id, a name
     * taken from what arrived — since whoever commented on the Backlog issue
     * has no account here.
     */
    private function syncComment(): void
    {
        $issue = $this->payload['issue'] ?? null;
        $externalId = is_array($issue) ? (string) ($issue['id'] ?? '') : (string) ($this->payload['issue_id'] ?? '');

        if ($externalId === '') {
            return;
        }

        $ticket = Ticket::query()->mirroring(self::SOURCE, $externalId)->first();

        if ($ticket === null) {
            return;
        }

        $body = (string) ($this->payload['body'] ?? $this->payload['comment']['body'] ?? '');

        if ($body === '') {
            return;
        }

        $author = $this->payload['author'] ?? $this->payload['user'] ?? null;
        $authorName = is_array($author) ? ($author['name'] ?? null) : null;

        BacklogSyncGuard::markEchoed($ticket->id);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'sender_name' => trim(($authorName ?? 'Backlog').' (via Backlog)'),
            'body' => $body,
        ]);

        TicketUpdated::dispatch($ticket);
    }

    /**
     * The id of the issue this delivery is about, whatever the event.
     *
     * @throws RuntimeException when the payload carries none — a malformed or
     *                          unrecognisable delivery, which is worth a
     *                          retry rather than being silently accepted.
     */
    private function externalIssueId(): string
    {
        $id = $this->payload['id'] ?? null;

        if ($id === null || $id === '') {
            throw new RuntimeException("Backlog webhook delivery ({$this->event}) carried no issue id.");
        }

        return (string) $id;
    }

    /**
     * After the last attempt, tell the connection so a beheerder finds out.
     *
     * A backstop rather than the only place this is recorded — every failing
     * attempt already calls it inside handle(). What this adds is the failure
     * modes that never reach there at all, such as a worker killed mid-run.
     */
    public function failed(): void
    {
        BacklogConnection::query()->find($this->connectionId)?->recordFailure();
    }
}
