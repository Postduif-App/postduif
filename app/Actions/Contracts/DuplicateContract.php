<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Start a new draft from a contract that has already been out.
 *
 * The same document, laid out the same way, asked of different people. That is
 * the whole of it, and it exists because the alternative is the author digging
 * the original PDF out of their downloads folder and drawing twenty boxes over
 * it again — for a lease or a set of terms that goes out every month to somebody
 * new.
 *
 * What is deliberately *not* copied is everything that happened to the original:
 * the signers, their tokens, their answers, their signatures, the signed copy
 * and the stamps that say when it finished. A contract's history belongs to the
 * contract it happened to, and carrying any of it across would be claiming
 * somebody signed something they have never seen.
 *
 * The original is never touched. It is usually completed, which means it is
 * evidence — see ContractStatus::isEvidence — and this is precisely the way to
 * reuse it without editing it, which the policy has forbidden since the first
 * signature landed.
 */
class DuplicateContract
{
    /**
     * @param  Contract  $contract  The one being reused. Left exactly as it was.
     * @param  User  $author  Whoever pressed the button — the new draft is
     *                        theirs, not the original author's, because they
     *                        are the one who will be chasing the signatures.
     * @param  string  $title  What to call the copy. Asked for rather than
     *                         derived, because a contract's title cannot be
     *                         changed after this moment and two rows reading
     *                         "Huurovereenkomst 2026" is a list nobody can use.
     */
    public function handle(Contract $contract, User $author, string $title): Contract
    {
        $source = $contract->source();

        if ($source === null) {
            throw new RuntimeException('A contract without its document cannot be duplicated.');
        }

        return DB::transaction(function () use ($contract, $author, $title, $source): Contract {
            $copy = Contract::create([
                'workspace_id' => $contract->workspace_id,
                'created_by' => $author->id,
                'title' => $title,
                'message' => $contract->message,
                'status' => ContractStatus::Draft,
                'page_count' => $contract->page_count,

                /*
                 * The same hash, and it is still true of the copy: the bytes
                 * are duplicated rather than re-made, so the file this row
                 * points at is the file that hash was taken over. Re-running
                 * Ghostscript would produce a different document — a rewrite of
                 * a rewrite — and the point of a duplicate is that it is the
                 * same document.
                 */
                'source_hash' => $contract->source_hash,

                /*
                 * No deadline, no channel. Both are decisions about an
                 * invitation, and there is no invitation yet — sending is where
                 * they are chosen, and inheriting a deadline that has already
                 * passed would hand somebody a draft that is dead on arrival.
                 */
            ]);

            /*
             * The boxes, geometry and all.
             *
             * signer_index rides along, pointing at positions in a list the
             * copy does not have yet, and that is safe rather than sloppy: when
             * the new signers are written down, SaveContractSigners walks every
             * field and clamps it into the new list — so a two-party layout
             * stays a two-party layout, and one with fewer signers than before
             * folds onto the last of them where the author can see it.
             */
            foreach ($contract->fields as $field) {
                $copy->fields()->create([
                    'page' => $field->page,
                    'x' => $field->x,
                    'y' => $field->y,
                    'width' => $field->width,
                    'height' => $field->height,
                    'type' => $field->type,
                    'label' => $field->label,
                    'is_required' => $field->is_required,
                    'position' => $field->position,
                    'signer_index' => $field->signer_index,
                ]);
            }

            /*
             * The PDF, copied on the disk rather than referenced.
             *
             * Two rows pointing at one file would mean deleting either contract
             * takes the document out from under the other — and the media
             * library's own delete does exactly that, without asking who else
             * is looking.
             *
             * Last inside the transaction, so that a failure anywhere above
             * leaves no stray file behind.
             */
            $source->copy($copy, Contract::SOURCE);

            return $copy;
        });
    }
}
