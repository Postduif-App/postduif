<?php

namespace App\Actions\Contracts;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Enums\ContractStatus;
use App\Mail\ContractRequestMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Turn a draft into something people have been asked to sign.
 *
 * One action rather than "add the signers" and "send it", because the two are
 * not separable in any way that helps: a signer without an invitation is a row
 * nobody will ever act on, and an invitation without a row is a link that opens
 * nothing. What the epic calls "van concept naar verstuurd" is one step.
 *
 * Everybody gets their link at the same time. Sequential signing — first A,
 * then B — is out of scope, although the column that would carry it is already
 * there; see the migration for why it was worth adding before it was needed.
 */
class SendContract
{
    public function __construct(private ResolveWorkspaceMailer $resolveMailer) {}

    /**
     * @param  list<array{name: string, email: string, user_id?: int|null}>  $signers
     *                                                                                 In the order they were named, which becomes signing_order — and
     *                                                                                 which the fields already point at through signer_index.
     * @param  int|null  $validForDays  Counted from now. Null leaves whatever
     *                                  deadline the contract already had, including none.
     */
    public function handle(Contract $contract, array $signers, ?int $validForDays = null): Contract
    {
        if ($signers === []) {
            throw new RuntimeException('A contract cannot be sent to nobody.');
        }

        if (! $contract->hasSource()) {
            throw new RuntimeException('A contract cannot be sent without a document.');
        }

        DB::transaction(function () use ($contract, $signers, $validForDays): void {
            /*
             * The signers are replaced rather than added to.
             *
             * This runs on a draft — the policy sees to that — so there is
             * nobody holding a link yet and nothing to invalidate. Replacing
             * makes the screen honest: the list somebody edited is the list
             * that gets invited, including the address they removed.
             *
             * Deleted one at a time rather than in a mass delete: a signer
             * carries media, and a query builder delete fires no events. The
             * same trap Contract::booted spells out.
             */
            $contract->signers()->each(fn (ContractSigner $signer) => $signer->delete());

            foreach (array_values($signers) as $order => $signer) {
                ContractSigner::create([
                    'contract_id' => $contract->id,
                    'user_id' => $signer['user_id'] ?? null,
                    'name' => trim($signer['name']),
                    'email' => trim($signer['email']),
                    'token' => ContractSigner::freshToken(),
                    'signing_order' => $order,
                ]);
            }

            $contract->update([
                'status' => ContractStatus::Sent,
                ...$validForDays === null
                    ? []
                    : ['expires_at' => now()->addDays($validForDays)],
            ]);
        });

        /*
         * Mailed after the transaction, never inside it.
         *
         * A mail is the one side effect there is no rollback for: a send inside
         * a transaction that then rolled back would have put a link to a
         * non-existent contract in somebody's inbox. The same note
         * CreateTransfer carries, and it matters more here — the recipient is
         * being asked to sign something.
         */
        $contract->refresh()->load('signers');

        $this->invite($contract);

        return $contract;
    }

    /**
     * Send this contract's invitation to everybody who has not answered.
     *
     * Shared with the reminder, which is the same mail a second time — see
     * RemindContractSigners. Keeping one method means the link somebody gets in
     * a reminder cannot drift from the one in the invitation.
     *
     * @param  list<ContractSigner>|null  $only  A subset, for a reminder. Null
     *                                           for everybody, which is what sending does.
     */
    public function invite(Contract $contract, ?array $only = null): void
    {
        // Resolved once for the whole list rather than per recipient: it is the
        // same workspace for every one of them, and asking again per address
        // would rebuild the transport for each.
        $mailer = $this->resolveMailer->handle($contract->workspace);

        $recipients = $only ?? $contract->signers->all();

        foreach ($recipients as $signer) {
            /*
             * Handed the contract it came from rather than left to fetch it.
             *
             * The mailable reads the title and the author off it, and lazy
             * loading is switched off in this application — so without this the
             * first mail of a send throws rather than going out. Setting it
             * also means twenty recipients are twenty mails and not twenty
             * extra queries: it is the same contract for all of them.
             */
            $signer->setRelation('contract', $contract);

            Mail::mailer($mailer)
                ->to($signer->email)
                ->send(new ContractRequestMail($signer));
        }
    }
}
