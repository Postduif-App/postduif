<?php

namespace App\Actions\Contracts;

use App\Models\ContractSigner;
use Illuminate\Support\Facades\DB;

/**
 * Somebody read it and said no.
 *
 * An outcome rather than a failure, and the whole reason it exists as a button:
 * without one, refusing means closing the tab, and closing the tab is
 * indistinguishable from being on holiday. The author would sit waiting for
 * somebody who has already decided.
 *
 * The reason is optional and asked for anyway. "Niet akkoord met artikel 4" is
 * the difference between a contract that gets amended and one that gets sent
 * again unchanged.
 */
class DeclineContract
{
    /**
     * @throws SigningRefused When there is nothing left to refuse.
     */
    public function handle(ContractSigner $signer, ?string $reason = null): ContractSigner
    {
        if (! $signer->canStillSign()) {
            throw new SigningRefused(__('contracts.sign.errors.closed'));
        }

        return DB::transaction(function () use ($signer, $reason): ContractSigner {
            /*
             * The same conditional update the signing uses, and for the same
             * reason: two requests arriving together must not both succeed, and
             * a refusal landing on a row that was signed a millisecond ago has
             * to lose rather than overwrite.
             *
             * The database holds the other half of this — a row cannot carry
             * both a signature and a refusal, whatever route reaches it. See the
             * check constraint in the migration.
             */
            $claimed = ContractSigner::query()
                ->whereKey($signer->id)
                ->whereNull('signed_at')
                ->whereNull('declined_at')
                ->update([
                    'declined_at' => now(),
                    'decline_reason' => $reason === null || trim($reason) === ''
                        ? null
                        : trim($reason),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new SigningRefused(__('contracts.sign.errors.already'));
            }

            /*
             * No IP address and no user agent, deliberately.
             *
             * Those are on the row to support a signature, which is a claim
             * somebody may one day have to stand behind. A refusal is a claim
             * about nothing — there is no document bearing this person's name —
             * so recording where they were sitting when they declined would be
             * collecting something for no purpose it could ever serve.
             */
            $signer->refresh();

            $signer->contract->settleIfEverybodyHasAnswered();

            return $signer;
        });
    }
}
