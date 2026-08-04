<?php

namespace App\Actions\Secrets;

use App\Actions\Chat\SendMessage;
use App\Models\Channel;
use App\Models\SentSecret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;

/**
 * Put a secret aside for somebody, and — where there is a channel — say in it
 * that the secret is there.
 *
 * Note what this action never sees: the secret. The ciphertext and the nonce
 * arrive already made from the sender's browser, and the key that would turn
 * them back into words never left it. That is not an accident of the interface —
 * it is the whole promise, and it is why there is no encrypting to be found
 * here the way there is in SecretValue::record().
 */
class SendSecret
{
    public function __construct(private SendMessage $sendMessage) {}

    /**
     * @param  string  $ciphertext  Already encrypted, and unreadable to us.
     * @param  string  $iv  The nonce that went with it.
     * @param  string|null  $password  A second gate, hashed here and checked on
     *                                 the way out. Null when the link alone is
     *                                 the whole of it.
     * @param  Workspace  $workspace  Where it belongs. Passed rather than read
     *                                off the channel, because there may be no
     *                                channel — see below.
     * @param  Channel|null  $channel  The room to announce it in, or null to
     *                                 announce it nowhere. A link made from the
     *                                 secrets page has no room and wants none:
     *                                 it is going into a mail, or being read out
     *                                 over the phone.
     * @param  User|null  $recipient  Who it is for, or null when nobody was
     *                                named. Never a lock either way — the link
     *                                is the credential — so this is a label for
     *                                the card and the list, no more.
     */
    public function handle(
        Workspace $workspace,
        ?Channel $channel,
        User $sender,
        ?User $recipient,
        string $label,
        string $ciphertext,
        string $iv,
        int $validForDays,
        ?string $password = null,
    ): SentSecret {
        /*
         * No transaction, unlike CreateSecretRequest: there is one row and
         * nothing to keep in step with it. The message goes out afterwards for
         * the same reason it does there — a message is not something a rollback
         * can take back.
         */
        $secret = SentSecret::create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel?->id,
            'created_by' => $sender->id,
            'recipient_id' => $recipient?->id,
            'label' => $label,
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'password_hash' => $password === null ? null : Hash::make($password),
            'expires_at' => now()->addDays($validForDays),
        ]);

        /*
         * The link in the message carries no fragment, and cannot: the key lives
         * only in the sender's browser, so this URL is deliberately not enough
         * to open anything. It is an announcement — the card PresentMessage
         * draws from it says who is being waited on, never what for.
         *
         * The sender is shown the complete link once, on the screen where they
         * made it, and passes it on themselves.
         */
        if ($channel !== null) {
            $this->sendMessage->handle(
                channel: $channel,
                author: $sender,
                body: trim($label.' '.route('sent-secrets.show', $secret->id)),
            );
        }

        return $secret;
    }

    /**
     * Take it back before it is read.
     *
     * Blanking rather than deleting, the same as a reveal: the card in the
     * channel has to be able to say it was withdrawn, and a row that vanished
     * would leave it saying nothing at all.
     */
    public function withdraw(SentSecret $secret): void
    {
        $secret->forceFill([
            'ciphertext' => '',
            'iv' => '',
            'password_hash' => null,
            // Backdated rather than a column of its own: for everybody
            // downstream "there is nothing here any more" is one state, and a
            // second flag meaning the same thing is a second thing to check.
            'expires_at' => now()->subSecond(),
        ])->save();
    }
}
