<?php

namespace App\Actions\Contracts;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Enums\ContractStatus;
use App\Events\ContractSent;
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
    public function __construct(
        private ResolveWorkspaceMailer $resolveMailer,
        private SaveContractSigners $saveSigners,
    ) {}

    /**
     * @param  list<array{name: string, email: string, user_id?: int|null}>  $signers
     *                                                                                 In the order they were named, which becomes signing_order — and
     *                                                                                 which the fields already point at through signer_index.
     * @param  int|null  $validForDays  Counted from now. Null leaves whatever
     *                                  deadline the contract already had, including none.
     * @param  int|null  $notifyChannelId  Where news about this contract is
     *                                     posted when there is nobody to DM — which is most contracts,
     *                                     because the signers are usually not members. Null leaves
     *                                     whatever was already chosen, including nothing.
     */
    public function handle(
        Contract $contract,
        array $signers,
        ?int $validForDays = null,
        ?int $notifyChannelId = null,
    ): Contract {
        if ($signers === []) {
            throw new RuntimeException('A contract cannot be sent to nobody.');
        }

        if (! $contract->hasSource()) {
            throw new RuntimeException('A contract cannot be sent without a document.');
        }

        /*
         * A template is not sent, it is copied and the copy is sent — see
         * InstantiateTemplate. Refused here rather than trusted to the screens
         * that call this, because sending one would be quietly destructive: the
         * author's signature is on the template exactly once, and marking it
         * Sent would put the only copy of it in front of a stranger and take
         * every future use of the template with it.
         */
        if ($contract->is_template) {
            throw new RuntimeException('A template is copied before it is sent, never sent itself.');
        }

        DB::transaction(function () use ($contract, $signers, $validForDays, $notifyChannelId): void {
            /*
             * The list is written by the same action the author has been
             * saving it with all along — see SaveContractSigners, which also
             * explains why a name that is already on the list keeps its row
             * and its token, and how the boxes follow the person they were
             * drawn for when the order changes.
             *
             * Sending does not get its own version of that: the list somebody
             * laid the contract out against has to be the list that gets
             * invited, or the boxes and the people would part company at the
             * last possible moment.
             */
            $this->saveSigners->handle($contract, $signers);

            $contract->update([
                'status' => ContractStatus::Sent,
                ...$validForDays === null
                    ? []
                    : ['expires_at' => now()->addDays($validForDays)],
                ...$notifyChannelId === null
                    ? []
                    : ['notify_channel_id' => $notifyChannelId],
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

        /*
         * After the invitations rather than before, and outside the
         * transaction. A contract that could not be sent should not announce
         * that it was, and everything hanging off this event — a workflow, a
         * webhook — is about a document that is now genuinely on its way.
         */
        ContractSent::dispatch($contract->id);

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
        /*
         * Everything the mail reads, fetched once for the whole list.
         *
         * mailTemplates is the newcomer here and the one with a real cost: it
         * is what a workspace wrote for these mails, and asking per recipient
         * would be a query per address for an answer that cannot differ between
         * them — see Workspace::mailTemplate, which reads the loaded collection
         * when there is one.
         */
        $contract->loadMissing(['author', 'workspace.mailTemplates']);

        // Resolved once for the whole list rather than per recipient: it is the
        // same workspace for every one of them, and asking again per address
        // would rebuild the transport for each.
        $mailer = $this->resolveMailer->handle($contract->workspace);

        /*
         * Never to somebody who has already answered.
         *
         * A reminder arrives here with its list already filtered, so for a long
         * time this could take everybody. What changed is the template: the
         * copy it produces carries the author's signature across, so their row
         * is on the contract from the first moment — signed, and about to be
         * asked to sign. See InstantiateTemplate.
         */
        $recipients = array_values(array_filter(
            $only ?? $contract->signers->all(),
            fn (ContractSigner $signer): bool => ! $signer->hasAnswered(),
        ));

        /*
         * Written in a language this contract chose, rather than in whatever
         * the application happened to be set to.
         *
         * It matters twice over. A reminder leaves from the scheduler and the
         * signed copy from a queued job, neither of which has a reader behind
         * it — so without this the first mail could ask in Dutch and the last
         * one confirm in English. And with per-language texts it decides which
         * of a workspace's own versions is used at all. See Contract::mailLocale.
         *
         * Said on the mailable rather than set on the application, because the
         * scope is exactly one message: the mailer switches the language for
         * the length of rendering it and puts it back. This runs inside a
         * request whose response still has to be rendered, and a language left
         * switched on would hand a member who mailed an English client an
         * English screen for the rest of the afternoon.
         */
        $locale = $contract->mailLocale();

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
                ->send((new ContractRequestMail($signer))->locale($locale));
        }
    }
}
