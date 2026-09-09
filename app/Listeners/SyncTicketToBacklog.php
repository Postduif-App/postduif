<?php

namespace App\Listeners;

use App\Enums\TicketEventType;
use App\Events\TicketChanged;
use App\Events\TicketCommented;
use App\Jobs\SyncTicketToBacklogJob;
use App\Models\Ticket;
use App\Support\BacklogSyncGuard;

/**
 * Plan a delivery to Backlog for a status change or a new comment on a ticket
 * that mirrors one of its issues.
 *
 * Not queued, the same choice DeliverContractWebhooks makes and for the same
 * reason: this only reads a ticket and dispatches a job, so there is nothing
 * here an outage on Backlog's end could hold up.
 *
 * Every ticket change and every comment passes through this once, whether it
 * came from a member here or from ProcessBacklogWebhookJob mirroring what
 * Backlog just told us — see BacklogSyncGuard for how the second kind is told
 * apart from the first and skipped, which is the whole reason this class is
 * not simply "dispatch the job".
 */
class SyncTicketToBacklog
{
    public function handleChanged(TicketChanged $event): void
    {
        // A ticket cares about far more than its status, but status is the
        // one field Backlog's issue has room for on this side of the sync —
        // see BacklogIssueMapper::toBacklogCategory.
        if ($event->type !== TicketEventType::StatusChanged) {
            return;
        }

        if (BacklogSyncGuard::wasEchoed($event->ticketId)) {
            return;
        }

        if (! $this->isMirrored($event->ticketId)) {
            return;
        }

        SyncTicketToBacklogJob::dispatch($event->ticketId, SyncTicketToBacklogJob::ACTION_STATUS);
    }

    public function handleCommented(TicketCommented $event): void
    {
        if (BacklogSyncGuard::wasEchoed($event->ticketId)) {
            return;
        }

        if (! $this->isMirrored($event->ticketId)) {
            return;
        }

        SyncTicketToBacklogJob::dispatch($event->ticketId, SyncTicketToBacklogJob::ACTION_COMMENT, $event->commentId);
    }

    /**
     * Whether this ticket is one Backlog needs to hear about at all.
     *
     * Only tickets that started as a Backlog issue qualify — see
     * Ticket::isExternal(). A ticket opened here that never came from Backlog
     * has nothing at the other end for a PATCH to reach.
     */
    private function isMirrored(int $ticketId): bool
    {
        return Ticket::query()->whereKey($ticketId)->whereNotNull('external_id')->exists();
    }
}
