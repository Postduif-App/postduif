<?php

namespace App\Actions\Contracts;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Mail\ContractSignedMail;
use App\Models\Contract;
use App\Models\ContractSigner;
use Illuminate\Support\Facades\Mail;

/**
 * Hand everybody who signed the document they signed.
 *
 * The last step of the feature, and the one that makes the rest of it worth
 * anything to the people outside the building: a signer has no account, so
 * without this the only copy of what they agreed to lives on somebody else's
 * server behind somebody else's login.
 *
 * Who is left out is the interesting half, as with the reminder. Whoever
 * refused gets nothing — they declined to enter into this, and posting them the
 * evidence unasked reads as not having noticed. Whoever never answered gets
 * nothing either: there is no copy of theirs to send, only somebody else's.
 *
 * Run twice by design. Once by RenderSignedContractJob the moment the document
 * exists, and again from the detail screen whenever somebody asks — see $again
 * for what separates the two.
 */
class SendSignedContract
{
    public function __construct(private ResolveWorkspaceMailer $resolveMailer) {}

    /**
     * @param  bool  $again  False for the automatic send, which skips anybody
     *                       already stamped: a job that gave up halfway down the list and was
     *                       retried must not post the document twice to the people it reached.
     *                       True for the button, where sending it again is the entire request —
     *                       somebody says they never got it, and the answer to that cannot be
     *                       "onze administratie zegt van wel".
     * @return int How many were mailed. Zero is an ordinary answer: everybody
     *             already has it, or nobody signed at all.
     */
    public function handle(Contract $contract, bool $again = false): int
    {
        $contract->loadMissing(['signers', 'workspace.mailTemplates', 'author']);

        /*
         * Nothing to attach, nothing to send. Not an exception: this is reached
         * from a queued job whose render may have failed, and a contract that is
         * signed but without its composed copy is a state the feature already
         * knows how to be in — see Contract::signedCopyState.
         */
        if ($contract->signedCopy() === null) {
            return 0;
        }

        $recipients = $contract->signers
            ->filter(fn (ContractSigner $signer): bool => $signer->hasSigned())
            ->filter(fn (ContractSigner $signer): bool => $again || $signer->copy_sent_at === null)
            ->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        // Resolved once for the whole list rather than per recipient: it is the
        // same workspace for every one of them. The same note SendContract has.
        $mailer = $this->resolveMailer->handle($contract->workspace);

        /*
         * In the same language the invitation went out in, whichever way this
         * was reached. Most of the time that is a queued job with no reader
         * behind it, so the alternative is the configured default — and a
         * client who was asked in English being thanked in Dutch. See
         * Contract::mailLocale, and SendContract::invite for why the language
         * is said on the mailable rather than set on the application.
         */
        $locale = $contract->mailLocale();

        foreach ($recipients as $signer) {
            /*
             * Handed the contract it came from rather than left to fetch it.
             * The mailable reads the title, the author and the attachment off
             * it, and lazy loading is switched off in this application — so
             * without this the first mail of a send throws rather than going
             * out.
             */
            $signer->setRelation('contract', $contract);

            Mail::mailer($mailer)
                ->to($signer->email)
                ->send((new ContractSignedMail($signer))->locale($locale));

            /*
             * Stamped per person, immediately after their own mail, rather than
             * for the whole list at the end.
             *
             * This is the one thing that makes a retry safe. If the transport
             * gives out on the fourth of five, the three that got through are
             * already marked and the retry picks up where it stopped. A single
             * update after the loop would either mark nobody — and post the
             * document twice to three people — or, done before the loop, mark
             * everybody and leave two of them with nothing.
             */
            $signer->forceFill(['copy_sent_at' => now()])->save();
        }

        return $recipients->count();
    }
}
