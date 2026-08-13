<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\ContractCancelled;
use App\Events\ContractCompleted;
use App\Events\ContractDeclined;
use App\Events\ContractExpired;
use App\Events\ContractOpened;
use App\Events\ContractRenderFailed;
use App\Events\ContractSent;
use App\Events\ContractSigned;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\Workflow;
use App\Workflows\Triggers\ContractCancelledTrigger;
use App\Workflows\Triggers\ContractCompletedTrigger;
use App\Workflows\Triggers\ContractDeclinedTrigger;
use App\Workflows\Triggers\ContractExpiredTrigger;
use App\Workflows\Triggers\ContractOpenedTrigger;
use App\Workflows\Triggers\ContractRenderFailedTrigger;
use App\Workflows\Triggers\ContractSentTrigger;
use App\Workflows\Triggers\ContractSignedTrigger;
use App\Workflows\WorkflowTrigger;

/**
 * Set off the workflows that were waiting for something to happen to a
 * contract.
 *
 * One listener for eight events, unlike every other listener here, and the
 * reason is that they are eight views of one thing. The filtering is identical,
 * the payload is the same contract described the same way, and the only
 * difference between "verstuurd" and "verlopen" is which trigger key the
 * workflow stored. Eight files repeating that would be eight places to fix the
 * day a contract learns a new column.
 *
 * Laravel finds each method by the type of its first parameter — the same trick
 * DeliverContractWebhooks uses — so the eight little handle methods below are
 * the whole of the wiring.
 *
 * The events carry ids rather than models, so every one of these reads the row.
 * That is not waste: an event fired inside a transaction and read afterwards
 * has to be read afterwards, and by the time the counts below are worked out
 * the contract has to be the one on disk rather than the one somebody had in
 * their hands three lines earlier.
 */
class StartContractWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleSent(ContractSent $event): void
    {
        $this->start($event->contractId, ContractSentTrigger::class);
    }

    public function handleOpened(ContractOpened $event): void
    {
        $this->start($event->contractId, ContractOpenedTrigger::class, $event->signerId);
    }

    public function handleSigned(ContractSigned $event): void
    {
        $this->start($event->contractId, ContractSignedTrigger::class, $event->signerId);
    }

    public function handleDeclined(ContractDeclined $event): void
    {
        $this->start($event->contractId, ContractDeclinedTrigger::class, $event->signerId);
    }

    public function handleCompleted(ContractCompleted $event): void
    {
        $this->start($event->contractId, ContractCompletedTrigger::class);
    }

    public function handleCancelled(ContractCancelled $event): void
    {
        $this->start($event->contractId, ContractCancelledTrigger::class);
    }

    public function handleExpired(ContractExpired $event): void
    {
        $this->start($event->contractId, ContractExpiredTrigger::class);
    }

    public function handleRenderFailed(ContractRenderFailed $event): void
    {
        $this->start($event->contractId, ContractRenderFailedTrigger::class);
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     */
    private function start(string $contractId, string $trigger, ?string $signerId = null): void
    {
        $contract = Contract::query()
            ->with(['author', 'notifyChannel', 'signers', 'workspace'])
            ->find($contractId);

        /*
         * Gone between the dispatch and here, which the render failure can
         * genuinely produce: the job gives up on a contract somebody has since
         * deleted. Nothing to describe and nothing to start.
         */
        if ($contract === null) {
            return;
        }

        $signer = $signerId === null ? null : $contract->signers->firstWhere('id', $signerId);

        $this->startWorkflows->handle(
            $contract->workspace,
            $trigger,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $contract)
                ? $this->context($contract, $signer)
                : null,
        );
    }

    /**
     * Whether this workflow was written about this sort of contract.
     *
     * All three filters are optional and all three mean "everything" when they
     * are left alone, which is the reading that makes a workflow written in
     * five seconds do something useful. The narrowing is for the workspace that
     * sends contracts all day and wants one channel told about the offertes.
     */
    private function matches(Workflow $workflow, Contract $contract): bool
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // Loosely, because the id came out of a JSON column where 7 may well
        // be "7". A channel named rather than picked cannot be matched here and
        // is treated as no filter — see the field's own hint.
        if (filled($channelId) && ctype_digit((string) $channelId)
            && (int) $channelId !== $contract->notify_channel_id) {
            return false;
        }

        $authorId = $workflow->triggerSetting('author_id');

        if (filled($authorId) && (int) $authorId !== $contract->created_by) {
            return false;
        }

        return $this->titleMatches($workflow, $contract);
    }

    /**
     * Whether the title says one of the words this workflow watches for.
     *
     * On word boundaries, the same as the keyword trigger and for the same
     * reason: "offerte" should not fire on "geoffreerd". Any of the words is
     * enough — a list here is an "of", which is how somebody reading
     * "offerte, prijsopgave" in the box would take it.
     */
    private function titleMatches(Workflow $workflow, Contract $contract): bool
    {
        $words = array_filter(array_map(
            fn (mixed $word): string => trim((string) $word),
            (array) $workflow->triggerSetting('title_words', []),
        ));

        if ($words === []) {
            return true;
        }

        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $contract->title) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the trigger saw, exactly as the ContractTrigger family promises it.
     *
     * The counts and the days are worked out here rather than left to the
     * condition, and that is the half of this class that earns its keep: a
     * condition can compare a number but cannot produce one. "Verloopt binnen
     * drie dagen" and "meer dan de helft heeft getekend" only exist because
     * these lines do.
     *
     * @return array<string, mixed>
     */
    private function context(Contract $contract, ?ContractSigner $signer): array
    {
        $signed = $contract->signers->filter(fn (ContractSigner $one): bool => $one->hasSigned())->count();
        $declined = $contract->signers->filter(fn (ContractSigner $one): bool => $one->hasDeclined())->count();

        return [
            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'url' => route('chat.contracts.show', [$contract->workspace, $contract]),
                'expires_at' => $contract->expires_at?->toDateString(),
                /*
                 * Null rather than a number when there is no deadline, so a
                 * condition asking "binnen drie dagen" says no for a contract
                 * that can never run out — where a 0 or a 999 would both be a
                 * lie in one direction or the other.
                 *
                 * Whole days and never negative: a contract that ran out
                 * yesterday is at nought, which is what "nog nul dagen" means
                 * to whoever reads it.
                 */
                'days_until_expiry' => $contract->expires_at === null
                    ? null
                    : max(0, (int) now()->startOfDay()->diffInDays($contract->expires_at->startOfDay(), false)),
                'page_count' => $contract->page_count,
                'signer_count' => $contract->signers->count(),
                'signed_count' => $signed,
                'declined_count' => $declined,
                'remaining' => $contract->signers->count() - $signed - $declined,

                // The names in one string, for a message that wants to say who
                // it is about without a step per person.
                'signers' => $contract->signers->pluck('name')->implode(', '),
                'download_url' => $contract->status->isEvidence()
                    ? route('chat.contracts.download', [$contract->workspace, $contract])
                    : null,
            ],
            'author' => [
                'id' => $contract->created_by,
                'name' => $contract->author?->name,
            ],
            /*
             * The channel news about this contract is posted in, which is
             * usually where a workflow wants to answer as well — and empty for
             * a contract that has none, so a step pointed at
             * {{ trigger.channel.id }} fails visibly rather than posting
             * somewhere nobody chose.
             */
            'channel' => [
                'id' => $contract->notify_channel_id,
                'name' => $contract->notifyChannel?->name,
            ],
            ...$signer === null ? [] : [
                'signer' => [
                    'id' => $signer->id,
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'order' => $signer->signing_order,
                    /*
                     * No account behind them means somebody from outside, which
                     * is the condition people reach for first: a customer and a
                     * colleague do not want the same message.
                     */
                    'is_external' => $signer->user_id === null,
                    'is_last' => $signed + $declined === $contract->signers->count(),
                    'signature_method' => $signer->signature_method?->value,
                    'decline_reason' => $signer->decline_reason,
                    'opened_at' => $signer->opened_at?->toIso8601String(),
                ],
            ],
        ];
    }
}
