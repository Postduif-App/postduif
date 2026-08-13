<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use App\Models\ContractSigner;

/**
 * Nudge the people who have not answered yet.
 *
 * The same mail a second time rather than a shorter one of its own, and
 * deliberately: whoever is being reminded has almost certainly lost the first
 * one, so what they need is the thing they lost — the document's name, who is
 * asking, and the link — not a note referring to it.
 *
 * Who is left out is the interesting half. Anybody who has signed or refused is
 * done with, and being mailed about a contract you already dealt with reads as
 * the sender not having noticed. And anybody nudged within the last day is
 * skipped, which is what keeps the button from being a way to sit on a
 * colleague's inbox — see ContractSigner::canBeRemindedAt.
 */
class RemindContractSigners
{
    public function __construct(private SendContract $send) {}

    /**
     * @return int How many were actually mailed. Zero is an ordinary answer —
     *             everybody has either answered or was nudged this morning — and the
     *             screen says so rather than claiming a reminder went out.
     */
    public function handle(Contract $contract): int
    {
        $contract->loadMissing('signers');

        /*
         * Each signer is handed the contract it came from before anything asks
         * it a question. canBeRemindedAt reaches through to the contract's own
         * state — expired, withdrawn — and lazy loading is switched off here,
         * so a signer that had to fetch it would throw rather than answer.
         */
        $contract->signers->each(
            fn (ContractSigner $signer) => $signer->setRelation('contract', $contract)
        );

        $due = $contract->signers
            ->filter(fn (ContractSigner $signer): bool => $signer->canBeRemindedAt())
            ->values();

        if ($due->isEmpty()) {
            return 0;
        }

        $this->send->invite($contract, array_values($due->all()));

        /*
         * Stamped after the mail rather than before.
         *
         * The wrong way round is the one that costs somebody a contract: mark
         * first and a transport that then fails leaves everybody marked as
         * reminded and unremindable for a day, with nothing in their inbox.
         * Stamping afterwards means a failure can at worst be tried again.
         */
        ContractSigner::query()
            ->whereIn('id', $due->pluck('id'))
            ->update(['reminded_at' => now()]);

        return $due->count();
    }
}
