<?php

namespace App\Actions\Contracts;

use App\Enums\ContractProgressKind;
use App\Events\ContractSigned;
use App\Jobs\RenderSignedContractJob;
use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use Illuminate\Support\Facades\DB;

/**
 * The moment a contract becomes a document with something behind it.
 *
 * Everything up to here has been reversible: a draft can be edited, a field can
 * be moved, a half-filled page can be abandoned. This is the one step that
 * cannot be taken back, so it is also the one that checks everything.
 *
 * Three things have to hold, and they are checked in this order because that is
 * the order in which they are worth telling somebody about. The document has to
 * be the one they were sent — everything else is moot if it is not. Every box
 * they were asked to fill in has to be dealt with. And they have to not have
 * signed already, which is the only one of the three that cannot be decided by
 * looking, and so is decided by the database.
 */
class SignContract
{
    public function __construct(private readonly NotifyContractAuthor $notify) {}

    /**
     * @param  string  $ip  Recorded as part of the audit trail and nowhere else.
     *
     * @throws SigningRefused When the contract may not be signed as it stands.
     */
    public function handle(
        ContractSigner $signer,
        string $ip,
        ?string $userAgent = null,
    ): ContractSigner {
        if (! $signer->canStillSign()) {
            throw new SigningRefused(__('contracts.sign.errors.closed'));
        }

        $hash = $this->hashOfWhatIsOnDisk($signer);

        $this->assertEverythingRequiredIsDone($signer);

        $signer = DB::transaction(function () use ($signer, $hash, $ip, $userAgent): ContractSigner {
            /*
             * One statement, and the whole of the "only once" guarantee.
             *
             * The where clause is what makes it safe: two requests arriving
             * together both run this, the database serialises them, and the
             * second one finds no row whose signed_at is still null. It reports
             * zero rows changed and this method refuses — rather than the second
             * one quietly overwriting the first one's timestamp and IP address,
             * which is what an "if it is not signed yet" in PHP would do on a
             * bad day.
             *
             * Written through the query builder rather than on the model on
             * purpose: $signer->update() would issue an unconditional UPDATE
             * against the primary key, and there would be nothing to serialise.
             */
            $claimed = ContractSigner::query()
                ->whereKey($signer->id)
                ->whereNull('signed_at')
                ->whereNull('declined_at')
                ->update([
                    'signed_at' => now(),
                    'signed_document_hash' => $hash,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                throw new SigningRefused(__('contracts.sign.errors.already'));
            }

            /*
             * The values become answers now rather than at each save.
             *
             * A draft records what was typed; this is what turns it into an
             * answer. Doing it here means the difference between "leeg gelaten"
             * and "niet langs geweest" survives right up to the moment somebody
             * commits, which is the only moment it stops mattering.
             */
            ContractFieldValue::query()
                ->where('contract_signer_id', $signer->id)
                ->whereNull('filled_at')
                ->update(['filled_at' => now()]);

            $signer->refresh();

            /*
             * A template's author signing it is not news, and there is nothing
             * to render: what they have just done is put their half of a
             * document in place for the contracts that will be made from it —
             * see InstantiateTemplate, which carries this signature across to
             * every one of them. A rendered "signed copy" of a template would
             * be a finished agreement between one person and nobody.
             */
            if ($signer->contract->is_template) {
                return $signer;
            }

            /*
             * Inside the transaction that just stamped this signer, so a
             * contract can never be observed with everybody signed and a status
             * that still says otherwise.
             */
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
                $this->notify->handle($signer->contract, ContractProgressKind::Signed, $signer);
            }

            return $signer;
        });

        /*
         * Announced after the commit, and outside the transaction rather than
         * with afterCommit on the listener, because there is nothing to gain
         * from being inside one: everything this event carries is already
         * written, and the listener it wakes only plans deliveries. Outside is
         * also the honest place for it — an event that fires inside a
         * transaction is an event that can be rolled back after the world has
         * been told.
         *
         * A template announces nothing, for the reason set out above: its
         * author signing it is not an agreement, and a subscriber would read
         * "getekend" about a document that was never sent to anybody.
         */
        if (! $signer->contract->is_template) {
            ContractSigned::dispatch($signer->contract->id, $signer->id);
        }

        return $signer;
    }

    /**
     * The sha256 of the file as it sits on disk right now, checked against what
     * was recorded when it was stored.
     *
     * This is the check the whole feature stands on. A signature means nothing
     * unless it can be said what it was put under, and the only way to say that
     * is to have taken the measurement at the time. If the two disagree, the
     * document has been replaced since it was sent — which nothing in the
     * application does, and which is exactly why it must stop everything rather
     * than be logged and shrugged at.
     */
    private function hashOfWhatIsOnDisk(ContractSigner $signer): string
    {
        $media = $signer->contract->source();

        if ($media === null || ! is_file($media->getPath())) {
            throw new SigningRefused(__('contracts.sign.errors.no_document'));
        }

        $hash = hash_file('sha256', $media->getPath());

        if ($hash === false) {
            throw new SigningRefused(__('contracts.sign.errors.no_document'));
        }

        if ($signer->contract->source_hash !== null
            && ! hash_equals($signer->contract->source_hash, $hash)) {
            throw new SigningRefused(__('contracts.sign.errors.document_changed'));
        }

        return $hash;
    }

    /**
     * Every box this person was asked to deal with has been dealt with.
     *
     * Asked through ContractField::isSatisfiedBy rather than by looking at the
     * values directly, because "afgehandeld" is not one question: a typed field
     * needs something in it, a signature needs a stamp put there by
     * StoreSignature, and an optional box needs nothing at all.
     *
     * Note what this catches for free: a signer who put down a signature and
     * then cleared it again has fields whose filled_at was taken back off, so
     * they cannot sign — which is the honest answer, and one nobody had to
     * write a rule for.
     */
    private function assertEverythingRequiredIsDone(ContractSigner $signer): void
    {
        $signer->contract->loadMissing('fields');

        $values = $signer->values()->get()->keyBy('contract_field_id');

        $missing = $signer->contract->fields
            ->filter(fn (ContractField $field): bool => $field->belongsToSigner($signer))
            ->reject(fn (ContractField $field): bool => $field->isSatisfiedBy($values->get($field->id)));

        if ($missing->isNotEmpty()) {
            throw new SigningRefused(trans_choice(
                'contracts.sign.errors.incomplete',
                $missing->count(),
                ['fields' => $missing->pluck('label')->implode(', ')],
            ));
        }
    }
}
