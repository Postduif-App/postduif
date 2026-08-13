<?php

namespace App\Actions\Contracts;

use App\Enums\ContractProgressKind;
use App\Events\ContractDeclined;
use App\Jobs\RenderSignedContractJob;
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
    public function __construct(private readonly NotifyContractAuthor $notify) {}

    /**
     * @throws SigningRefused When there is nothing left to refuse.
     */
    public function handle(ContractSigner $signer, ?string $reason = null): ContractSigner
    {
        if (! $signer->canStillSign()) {
            throw new SigningRefused(__('contracts.sign.errors.closed'));
        }

        $signer = DB::transaction(function () use ($signer, $reason): ContractSigner {
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

            /*
             * The same silence a template's author gets for signing it — see
             * SignContract. Refusing your own template is an odd thing to do,
             * but it is reachable from the signing page, and it should leave
             * the template unusable rather than announce a refusal to the
             * person who made it.
             */
            if ($signer->contract->is_template) {
                return $signer;
            }

            if ($signer->contract->settleIfEverybodyHasAnswered()) {
                /*
                 * afterCommit, and it is not optional. A job dispatched inside
                 * a transaction can be picked up by a worker before the
                 * transaction commits — the queue is a different connection —
                 * and it would then look for a contract that, as far as it can
                 * see, has not been completed yet.
                 *
                 * The author is told by that job rather than from here: the
                 * message they want carries a link to the finished document,
                 * and the finished document does not exist until it has run.
                 */
                RenderSignedContractJob::dispatch($signer->contract->id)->afterCommit();
            } else {
                /*
                 * One of several, with others still to come. Told now, because
                 * this is news in its own right — and told with the number
                 * still outstanding in it, so it cannot be mistaken for "het is
                 * rond".
                 */
                $this->notify->handle($signer->contract, ContractProgressKind::Declined, $signer);
            }

            return $signer;
        });

        /*
         * After the commit, outside the transaction, and never for a template —
         * the same three decisions SignContract explains at more length.
         *
         * A refusal is news in exactly the way a signature is: it is the answer
         * a system that sent this contract has been waiting for, and one that
         * only ever heard about signatures would keep a refused contract open
         * forever.
         */
        if (! $signer->contract->is_template) {
            ContractDeclined::dispatch($signer->contract->id, $signer->id);
        }

        return $signer;
    }
}
