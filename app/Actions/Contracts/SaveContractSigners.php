<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write down who is going to sign, without asking any of them yet.
 *
 * Its own step because of the order the work is done in. The boxes on a
 * contract belong to people — "hier tekent de verhuurder, daar de huurder" —
 * and the editor cannot offer that choice against a list that does not exist.
 * Naming the signers first turns signer_index from a number into a name, which
 * is the difference between a layout somebody can check and one they have to
 * remember.
 *
 * Nothing here sends anything. That is the whole point of splitting it off from
 * SendContract: this can be done, undone and done again while the contract is
 * still nobody's business but the author's.
 *
 * The rows it writes are real signers with real tokens all the same, because a
 * half-signer with no token would be a second kind of row for everything
 * downstream to know about. What keeps them harmless is that nobody has been
 * told the link exists — see SendContract for the moment that changes.
 */
class SaveContractSigners
{
    /**
     * @param  list<array{name: string, email: string, user_id?: int|null}>  $signers
     *                                                                                 In the order they were named, which becomes signing_order — and
     *                                                                                 which the fields point at through signer_index.
     */
    public function handle(Contract $contract, array $signers): void
    {
        if ($signers === []) {
            throw new RuntimeException('A contract cannot be signed by nobody.');
        }

        DB::transaction(function () use ($contract, $signers): void {
            $existing = $contract->signers()
                ->get()
                ->keyBy(fn (ContractSigner $signer): string => mb_strtolower($signer->email));

            /**
             * Where each old position ends up, so that the boxes can follow the
             * person they were drawn for.
             *
             * @var array<int, int> $moved
             */
            $moved = [];

            $kept = [];

            // The key is the signing order, which is why the parameter is typed
            // as a list: the caller's ordering is the contract's ordering.
            foreach ($signers as $order => $row) {
                $address = mb_strtolower(trim($row['email']));

                /*
                 * Somebody already on the list keeps their row, and with it
                 * their token.
                 *
                 * Matched on the address rather than replaced wholesale, which
                 * matters the moment this is saved twice: adding a third signer
                 * must not quietly rotate the links of the two who were already
                 * there. On a draft nobody holds one yet, but this same action
                 * is what sending runs, and a re-send is exactly the case where
                 * a rotated token would break a link somebody is holding.
                 */
                $signer = $existing->get($address) ?? new ContractSigner([
                    'contract_id' => $contract->id,
                    'token' => ContractSigner::freshToken(),
                ]);

                if ($signer->exists) {
                    $moved[$signer->signing_order] = $order;
                }

                $signer->fill([
                    'user_id' => $row['user_id'] ?? null,
                    'name' => trim($row['name']),
                    'email' => trim($row['email']),
                    'signing_order' => $order,
                ])->save();

                $kept[] = $signer->id;
            }

            /*
             * Whoever was taken off the list goes, one at a time rather than in
             * a mass delete: a signer carries media, and a query builder delete
             * fires no events. The same trap Contract::booted spells out.
             */
            $contract->signers()
                ->whereNotIn('id', $kept)
                ->get()
                ->each(fn (ContractSigner $signer) => $signer->delete());

            $this->repointFields($contract, $moved, count($signers));
        });

        $contract->load('signers');
    }

    /**
     * Send every box after the person it was drawn for.
     *
     * The rule that stops a reorder from being a silent disaster. Boxes point
     * at a position, not at a person, so inserting somebody at the top of the
     * list would otherwise hand the first signer's signature box to whoever now
     * stands in their place — a change nobody made and nobody would see.
     *
     * A box whose owner was removed altogether has nowhere to follow, and is
     * left with the last signer rather than deleted. Losing the box would lose
     * the geometry somebody drew; pointing it at somebody real means the author
     * finds it in the editor, on the page, marked with a name that surprises
     * them.
     *
     * @param  array<int, int>  $moved  Old position to new.
     */
    private function repointFields(Contract $contract, array $moved, int $count): void
    {
        $contract->load('fields');

        foreach ($contract->fields as $field) {
            $was = $field->signerIndex();
            $now = $moved[$was] ?? min($was, $count - 1);

            if ($field->signer_index === $now) {
                continue;
            }

            $field->update(['signer_index' => $now]);
        }
    }
}
