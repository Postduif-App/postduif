<?php

namespace App\Notifications;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Enums\ContractProgressKind;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\SendsPushover;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\PushoverMessage;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Er is iets gebeurd met een contract dat je verstuurd hebt."
 *
 * One class for all three things that can happen rather than three, because the
 * recipient is the same person and the difference between them is a sentence.
 * What the difference must never become is invisible: a notification that reads
 * the same whether one of four people signed or the last one did would train
 * somebody to ignore the one that means "het is rond" — which is the only one
 * they have to act on.
 *
 * So the kind is carried explicitly and every line on the way out branches on
 * it. See ContractProgressKind.
 */
class ContractProgress extends Notification implements SendsPushover, SendsWebPush, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Contract $contract,
        public readonly ContractProgressKind $kind,
        /**
         * Whose action this is about. Null for the completion, which is about
         * the contract rather than about any one person.
         */
        public readonly ?ContractSigner $signer = null,
        /**
         * Where the finished document can be fetched, when there is one. Null
         * while it is still being composed, and — the case worth having a null
         * for — when composing it failed. A notification that promised a
         * download and linked to a 404 would be worse than one that said
         * nothing about it.
         */
        public readonly ?string $downloadUrl = null,
    ) {
        // Ahead of the slow work: somebody has been waiting for this.
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            $notifiable->notify_via_mail ? 'mail' : null,
            $notifiable->wantsPushover() ? PushoverChannel::class : null,
            $notifiable->wantsWebPush() ? WebPushChannel::class : null,
        ]));
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            // On the workspace's own mailer when it has one — see
            // ChannelActivity for why it is resolved here and not passed in.
            ->mailer(app(ResolveWorkspaceMailer::class)->handle($this->contract->workspace))
            ->subject($this->subject())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->sentence());

        if ($this->kind === ContractProgressKind::Completed) {
            $mail->line(__('notifications.contract.tally', [
                'signed' => $this->contract->signedCount(),
                'total' => $this->contract->signers->count(),
            ]));
        }

        if ($this->signer?->decline_reason !== null) {
            $mail->line('“'.$this->signer->decline_reason.'”');
        }

        /*
         * The link is offered rather than the file attached.
         *
         * A signed contract is on the private disk behind a policy, and putting
         * it in a mailbox takes it out from behind that policy for good — mail
         * gets forwarded, archived and searched by things nobody chose. The
         * download costs one click and stays governed.
         */
        if ($this->downloadUrl !== null) {
            $mail->action(__('notifications.contract.download'), $this->downloadUrl);
        } elseif ($this->kind === ContractProgressKind::Completed) {
            $mail->line(__('notifications.contract.no_copy_yet'));
        }

        return $mail;
    }

    public function toPushover(User $notifiable): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->subject(),
            body: $this->sentence(),
            url: $this->downloadUrl,
        );
    }

    /**
     * The same news as a bubble in the browser's own tray.
     *
     * Tagged per contract, for the reason ChannelActivity's docblock gives for
     * sending one summary instead of one notification per message: a contract
     * with four signers produces four pieces of news over a week, and four
     * bubbles about the same document is the pile people switch notifications
     * off over. Under one tag the newest state of this contract replaces the
     * previous one — which is also the honest thing to show, because "nog twee
     * te gaan" stops being true the moment the third person signs. Per contract
     * and not per workspace: two contracts running at once are two separate
     * things to act on, and letting one hide the other would lose news.
     *
     * Renotify is deliberately on. Unlike a growing unread count, every one of
     * these is a new event somebody has to act on — a refusal most of all — and
     * a bubble that silently swaps itself out is one nobody sees.
     *
     * The click goes to the contract's own page rather than to the download.
     * That URL is a signed link with a lifetime, and this payload is decrypted
     * and stored by a push service outside the EU; the contract's own address
     * decides per reader where it takes them, behind a login, on our domain.
     */
    public function toWebPush(User $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: $this->subject(),
            // The one sentence, and not the decline reason: somebody's grounds
            // for refusing are the last thing to hand a push service.
            body: $this->sentence(),
            url: route('chat.contracts.show', [$this->contract->workspace, $this->contract]),
            tag: 'contract-'.$this->contract->id,
            renotify: true,
        );
    }

    /**
     * The one line that has to survive being read on a lock screen.
     *
     * The title carries the difference rather than the body, because a push
     * notification is often only ever seen as its title.
     */
    private function subject(): string
    {
        return match ($this->kind) {
            ContractProgressKind::Signed => __('notifications.contract.subject_signed', [
                'name' => $this->signer->name ?? '',
                'title' => $this->contract->title,
            ]),
            ContractProgressKind::Declined => __('notifications.contract.subject_declined', [
                'name' => $this->signer->name ?? '',
                'title' => $this->contract->title,
            ]),
            ContractProgressKind::Completed => __('notifications.contract.subject_completed', [
                'title' => $this->contract->title,
            ]),
        };
    }

    private function sentence(): string
    {
        return match ($this->kind) {
            ContractProgressKind::Signed => trans_choice('notifications.contract.body_signed', $this->outstanding(), [
                'name' => $this->signer->name ?? '',
                'title' => $this->contract->title,
            ]),
            ContractProgressKind::Declined => __('notifications.contract.body_declined', [
                'name' => $this->signer->name ?? '',
                'title' => $this->contract->title,
            ]),
            ContractProgressKind::Completed => __('notifications.contract.body_completed', [
                'title' => $this->contract->title,
            ]),
        };
    }

    /**
     * How many people have still to answer.
     *
     * The number that decides the wording: "nog één" and "nog drie" are
     * different pieces of news, and "en daarmee is iedereen langs geweest" is
     * a third — which is why this notification is not sent for that case at
     * all. See NotifyContractAuthor.
     */
    private function outstanding(): int
    {
        return $this->contract->signers
            ->reject(fn (ContractSigner $signer): bool => $signer->hasAnswered())
            ->count();
    }
}
