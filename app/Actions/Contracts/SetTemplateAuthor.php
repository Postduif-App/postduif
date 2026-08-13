<?php

namespace App\Actions\Contracts;

use App\Models\Contract;
use App\Models\ContractField;
use App\Models\ContractSigner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Put the author on a template as a party, or take them off again.
 *
 * The one switch that changes what every box on a template means. A template
 * numbers its parties from zero: with the author signing along they are
 * position zero and the recipients follow at one, without them the recipients
 * start at zero themselves. So flipping the switch shifts every signer_index by
 * one, and a version of this that only wrote the signer row would silently hand
 * the tenant's signature box to the landlord.
 *
 * Written here rather than handed to SaveContractSigners, which does look like
 * the action for this. That one is given the whole roster and repoints every box
 * against it — see repointFields — and a template's roster is one person plus a
 * number. Handing it a list of one would mean telling it the contract has
 * exactly one party, and it would clamp every recipient's box onto position
 * zero. It is the right action for a contract whose signers are all known by
 * name, which is precisely what a template's are not.
 *
 * Nothing here signs anything. What comes out is a row with a token on it, and
 * the author walks through the ordinary signing page like everybody else — see
 * Contract::isSignable, which says yes to a draft template for exactly this
 * reason.
 */
class SetTemplateAuthor
{
    /**
     * @param  User  $author  Whoever is preparing this template. Their name and
     *                        address go on the row because the signature has to
     *                        be attributable to a person, and on a template
     *                        that person is always the one holding the screen.
     * @return bool Whether anything changed, so the caller can tell "aangezet"
     *              from "stond al aan" without asking again.
     */
    public function handle(Contract $template, User $author, bool $signsAlong): bool
    {
        if (! $template->isTemplate()) {
            throw new RuntimeException('Only a template numbers its parties this way.');
        }

        $template->loadMissing(['fields', 'signers']);

        $existing = $template->templateSigner();

        if ($signsAlong === ($existing !== null)) {
            return false;
        }

        DB::transaction(function () use ($template, $author, $signsAlong, $existing): void {
            if ($signsAlong) {
                $template->signers()->create([
                    'token' => ContractSigner::freshToken(),
                    'user_id' => $author->id,
                    'name' => $author->name,
                    'email' => $author->email,
                    'signing_order' => 0,
                ]);
            } else {
                /*
                 * Taken down one row at a time, the way everything that removes
                 * a signer here does: the signature is media hanging off this
                 * model, and only the model's own delete event takes the file
                 * off the disk. See Contract::booted for the same trap one
                 * layer down.
                 *
                 * And it is what makes the switch reversible at all. Once the
                 * author has signed, ContractPolicy::update refuses everything
                 * — quite rightly, because moving a box under a signature would
                 * change what was agreed. Removing the signature is the honest
                 * way back to an editable template, which is why this action is
                 * not behind that rule.
                 */
                $existing?->delete();
            }

            $this->shiftFields($template, $signsAlong ? 1 : -1);
        });

        $template->load(['fields', 'signers']);

        return true;
    }

    /**
     * Move every box one place along, in the direction the parties moved.
     *
     * Going up is exact: the recipients were at zero and up, they are now at
     * one and up, and nothing is lost.
     *
     * Coming down is where a decision has to be made, because the author's own
     * boxes were at position zero and position zero is now the first
     * recipient's. They are clamped there rather than deleted, which is the same
     * answer SaveContractSigners gives when somebody is taken off a list: losing
     * the box would lose geometry a person drew by hand, while pointing it at a
     * real party means they find it in the editor, on the page, labelled with a
     * name that surprises them into fixing it.
     */
    private function shiftFields(Contract $template, int $by): void
    {
        foreach ($template->fields as $field) {
            $now = max(0, $field->signerIndex() + $by);

            if ($field->signer_index === $now) {
                continue;
            }

            $field->update(['signer_index' => $now]);
        }
    }

    /**
     * The lowest number of recipients this template's boxes will still fit in.
     *
     * Asked before the count is lowered, and it is the one rule that keeps
     * "aantal ontvangers" from quietly breaking a finished layout: a box drawn
     * for the third party of a four-party template is a box nobody can ever be
     * shown once the template says there are two. isReadyToSend would still say
     * yes — it counts fields, not who they are for — so the refusal has to
     * happen here, while somebody is looking at the number they typed.
     */
    public function recipientsNeededFor(Contract $template): int
    {
        $template->loadMissing(['fields', 'signers']);

        $highest = $template->fields
            ->map(fn (ContractField $field): int => $field->signerIndex())
            ->max() ?? 0;

        $signsAlong = $template->templateSigner() !== null;

        return max(1, $highest + 1 - ($signsAlong ? 1 : 0));
    }
}
