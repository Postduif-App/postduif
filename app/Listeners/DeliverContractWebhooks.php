<?php

namespace App\Listeners;

use App\Events\ContractCompleted;
use App\Events\ContractDeclined;
use App\Events\ContractSigned;
use App\Jobs\DeliverContractWebhookJob;
use App\Models\Contract;
use App\Models\ContractWebhook;

/**
 * Work out who wanted to hear this, and plan one delivery for each of them.
 *
 * The whole of the fan-out, and deliberately nothing else: it reads two things
 * from the database and dispatches jobs. Nothing here talks to the outside
 * world, which is what makes it safe to run in the request that just finished a
 * signature — a receiving system that is down cannot slow down, let alone
 * break, the moment somebody put their name to something.
 *
 * Not queued, unlike SendFormAnswers next door, and for the opposite reason to
 * the one that usually applies. Queueing this would put a job in front of the
 * jobs, so that a worker restart between the two would lose deliveries nobody
 * ever knew were owed. Doing the planning synchronously means that by the time
 * the signing request has returned, every delivery it implies is already on the
 * queue with its own retries.
 *
 * Three methods rather than one with a union type. Laravel discovers a listener
 * by the parameter of anything called handle*, so each of these registers
 * itself for exactly one event — and the reader of a stack trace gets a method
 * name that says which.
 */
class DeliverContractWebhooks
{
    public function handleSigned(ContractSigned $event): void
    {
        $this->plan(ContractWebhook::EVENT_SIGNED, $event->contractId);
    }

    public function handleDeclined(ContractDeclined $event): void
    {
        $this->plan(ContractWebhook::EVENT_DECLINED, $event->contractId);
    }

    public function handleCompleted(ContractCompleted $event): void
    {
        $this->plan(ContractWebhook::EVENT_COMPLETED, $event->contractId);
    }

    /**
     * One job per interested address.
     *
     * Two kinds of interest, and they do not overlap: the workspace's standing
     * subscriptions, and the address the API caller gave for this one contract.
     * A contract sent through the API by a system that also happens to keep a
     * subscription gets both, on purpose — they are two different arrangements
     * made by two different people, and quietly collapsing them into one would
     * mean an integration losing news the moment a colleague set up a
     * subscription of their own.
     */
    private function plan(string $event, string $contractId): void
    {
        $contract = Contract::query()->find($contractId);

        if ($contract === null) {
            return;
        }

        /*
         * Taken once, here, and carried through every retry. This is when the
         * thing happened; the moment a delivery finally got through is the
         * receiver's own business and is on the row for the beheerder.
         */
        $occurredAt = now()->toIso8601String();

        $subscriptions = ContractWebhook::query()
            ->where('workspace_id', $contract->workspace_id)
            ->active()
            ->get()
            /*
             * Filtered in PHP rather than with a jsonb containment query. A
             * workspace has a handful of these, they are already in memory, and
             * the alternative is an operator whose behaviour a reader has to go
             * and look up — see wants(), which is the same question asked in
             * words.
             */
            ->filter(fn (ContractWebhook $webhook): bool => $webhook->wants($event));

        foreach ($subscriptions as $webhook) {
            DeliverContractWebhookJob::dispatch($event, $contract->id, $webhook->id, $occurredAt);
        }

        /*
         * And the address this one contract was sent with, if it has one. Both
         * halves have to be there: a URL without a secret is a delivery nobody
         * could verify, which is worse than none — a receiver that accepts an
         * unsigned webhook accepts one from anybody.
         */
        if ($contract->callback_url !== null && $contract->callback_secret !== null) {
            DeliverContractWebhookJob::dispatch($event, $contract->id, null, $occurredAt);
        }
    }
}
