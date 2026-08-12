<?php

namespace App\Policies;

use App\Enums\WorkspaceAbility;
use App\Models\Contract;
use App\Models\User;

/**
 * Who may do what with one particular contract, from the inside.
 *
 * Nothing here is about the signer. They hold a token, which is a different kind
 * of proof entirely and is checked on the public route — the same split
 * TransferPolicy makes. These questions are about the people in the workspace
 * the document went out from.
 *
 * The line running through all of it is narrower than for a form: the author,
 * and whoever runs the workspace. Not every member, and not everybody in the
 * channel where the card happens to be. A contract usually carries somebody's
 * salary, their address or their terms, and the list of what colleagues are
 * having signed is not a workspace-wide noticeboard.
 */
class ContractPolicy
{
    /**
     * Whether somebody may start one at all.
     *
     * The one question with no contract to judge, so it asks the right rather
     * than the row. The feature flag is asked separately, by the middleware on
     * the routes: "deze workspace doet niet aan contracten" and "jij mag ze niet
     * versturen" are different answers and lead to different screens.
     */
    public function create(User $user, mixed $workspace): bool
    {
        return $workspace->allows($user, WorkspaceAbility::SendContracts);
    }

    /**
     * Seeing the document, who was asked, and how far they have got.
     *
     * The author or a workspace manager. The manager is not here to look over
     * colleagues' shoulders but because a contract sent to the wrong address has
     * to be stoppable by somebody who is still around, which the author on
     * holiday is not — the same reason TransferPolicy names them.
     */
    public function view(User $user, Contract $contract): bool
    {
        if ($contract->created_by === $user->id) {
            return true;
        }

        return $contract->workspace->allows($user, WorkspaceAbility::ManageWorkspace);
    }

    /**
     * Changing the document or the boxes drawn over it.
     *
     * The same two people, and one hard condition on top: only while it is still
     * a draft. Moving a signature box after the invitations have gone out would
     * change what somebody is agreeing to between reading it and signing it,
     * and that is precisely the thing a contract may never do.
     *
     * Withdrawing and starting again is the way to change a sent contract. That
     * is deliberately more work, because it is also more honest: everybody who
     * was asked gets told the old one is dead.
     */
    public function update(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && $contract->status->isOutstanding()
            && $contract->signers()->whereNotNull('signed_at')->doesntExist();
    }

    /**
     * Killing every link and closing it off.
     *
     * Allowed on a signed-but-not-finished contract, where update() is not:
     * stopping something is not the same as changing it, and a contract two of
     * three people have signed is exactly the one somebody most urgently needs
     * to be able to stop. What it cannot touch is a finished contract — that is
     * evidence, and withdrawing it after the fact would be rewriting what
     * happened.
     */
    public function cancel(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && $contract->status->isOutstanding();
    }

    /**
     * Nudging the people who have not signed yet.
     *
     * Only on a contract that is actually out — a draft has nobody to remind,
     * and a withdrawn one would be reminding people about something that no
     * longer exists. The throttle that stops this becoming harassment is not
     * here; it belongs to the action, because it is about how often rather than
     * about who.
     */
    public function remind(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && $contract->isSignable();
    }

    /**
     * Fetching the PDF — the source while it is out, the signed copy once it is
     * done.
     *
     * Same as view(): if somebody may see who signed and when, withholding the
     * document those facts are about would be an odd place to draw a line.
     */
    public function download(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract);
    }

    /**
     * Throwing it away for good.
     *
     * Never once it is completed, and not by an admin's judgement either. A
     * signed contract is the only thing here that somebody else relies on: the
     * person who signed it has a copy and a right to assume this one still
     * exists. What may be deleted is correspondence that came to nothing.
     */
    public function delete(User $user, Contract $contract): bool
    {
        return $this->view($user, $contract) && ! $contract->status->isEvidence();
    }
}
