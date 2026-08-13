<?php

namespace App\Events;

use App\Enums\TicketEventType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a ticket that its timeline records.
 *
 * The counterpart of TicketUpdated rather than a replacement for it, and the
 * difference is what each is for. TicketUpdated is a broadcast: it carries a
 * channel and a number, which is everything a screen needs to go and fetch the
 * ticket again, and nothing anybody could act on. This one carries what
 * changed and what it changed from — which is the whole question a workflow
 * asks. "Ging hij naar afgerond" cannot be answered by "ticket 12 is anders
 * dan het was".
 *
 * Dispatched from RecordTicketEvent, which is the one place every change to a
 * ticket already passes through — the same argument that action makes for its
 * own existence. Nothing else has to remember to do it, and anything that
 * writes a timeline row gets a trigger for free.
 *
 * ShouldDispatchAfterCommit, because every one of those calls sits inside a
 * transaction. A listener that started a workflow before the commit would hand
 * it a ticket the queue cannot see yet — and on a failed transaction, one that
 * never existed.
 */
class TicketChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  int|null  $actorId  Null when nothing with a face did this: the scheduler, a webhook.
     * @param  array<string, mixed>  $payload  Whatever the timeline row carries — usually from and to.
     */
    public function __construct(
        public readonly int $ticketId,
        public readonly TicketEventType $type,
        public readonly ?int $actorId = null,
        public readonly array $payload = [],
    ) {}
}
