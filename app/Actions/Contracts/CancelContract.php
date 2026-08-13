<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Events\ContractCancelled;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Stop a contract that is out.
 *
 * The one thing somebody needs when the wrong document went to the right person,
 * or the right one to the wrong address. It is deliberately allowed on a
 * half-signed contract, where editing is not: stopping something is not the same
 * as changing it, and a contract two of three people have signed is exactly the
 * one that most urgently needs stopping.
 *
 * What "de tokens dood maken" means here is worth spelling out, because the
 * obvious reading is wrong. The tokens are left exactly as they are. Rotating
 * them would make every outstanding link resolve to nothing, and a stranger who
 * followed one would get a 404 — leaving them to wonder whether they had the
 * wrong address, or whether something was broken, and eventually to telephone.
 *
 * What actually stops them is the status: ContractSigner::canStillSign asks the
 * contract, and a cancelled contract answers no. So the link still resolves, and
 * the person holding it is told it was withdrawn — see the five screens in
 * ContractSignController. The link is dead; the explanation is not.
 */
class CancelContract
{
    /**
     * @throws SigningRefused When there is nothing to stop.
     */
    public function handle(Contract $contract): Contract
    {
        if (! $contract->status->isOutstanding()) {
            throw new SigningRefused(__('contracts.errors.not_outstanding'));
        }

        return DB::transaction(function () use ($contract): Contract {
            /*
             * A conditional update, as the signing uses, and for a reason that
             * is easy to miss: the race here is not two people pressing cancel
             * — that is harmless — but cancel arriving at the same moment as
             * the last signature. Only one of the two may win, and whichever
             * does, the other must find nothing left to change rather than
             * write over it.
             */
            $claimed = Contract::query()
                ->whereKey($contract->id)
                ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::Sent->value])
                ->update([
                    'status' => ContractStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new SigningRefused(__('contracts.errors.not_outstanding'));
            }

            /*
             * After the claim, so this only ever announces a cancel that
             * actually happened — the race above is a cancel arriving together
             * with the last signature, and the loser must change nothing and
             * say nothing.
             */
            ContractCancelled::dispatch($contract->id);

            return $contract->refresh();
        });
    }
}
