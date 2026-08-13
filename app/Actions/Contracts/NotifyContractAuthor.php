<?php

namespace App\Actions\Contracts;

use App\Actions\Chat\AnnounceInbox;
use App\Actions\Chat\SendMessage;
use App\Actions\Chat\StartDirectMessage;
use App\Enums\ContractProgressKind;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Notifications\ContractProgress;

/**
 * Tell the person who asked for the signatures that something happened.
 *
 * Three ways at once, and they are three because they answer to three different
 * moments. The mail reaches somebody who is not looking at the application at
 * all. The inbox row is what makes the badge appear for somebody who is. And
 * the message in the chat is what makes it readable in passing — a line in a
 * conversation, with the link in it, rather than a number on an icon.
 *
 * The message is also what the inbox row points at, which is the reason it goes
 * first: an inbox item needs a channel and a message, and inventing a row with
 * nothing behind it would put a badge on a list that then had nothing to show.
 */
class NotifyContractAuthor
{
    /** How the application signs the line it writes in a channel. */
    public const BOT_NAME = 'Contracten';

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly StartDirectMessage $startDirectMessage,
        private readonly AnnounceInbox $announceInbox,
    ) {}

    /**
     * @param  ContractSigner|null  $signer  Whose action this is about, or null
     *                                       for the completion — which is about the contract.
     * @param  string|null  $downloadUrl  Where the finished document can be
     *                                    fetched. Only ever set for the completion, and only once it
     *                                    actually exists — see RenderSignedContractJob.
     */
    public function handle(
        Contract $contract,
        ContractProgressKind $kind,
        ?ContractSigner $signer = null,
        ?string $downloadUrl = null,
    ): void {
        $contract->loadMissing(['author', 'signers', 'workspace', 'notifyChannel']);

        $author = $contract->author;

        /*
         * Nobody to tell. The account that sent this contract is gone — see the
         * nullOnDelete in the migration, which is deliberate: a signed contract
         * outlives whoever asked for it. The record stays; the notification has
         * nowhere to go.
         */
        if ($author === null) {
            return;
        }

        $message = $this->postToChat($contract, $author, $kind, $signer);

        if ($message !== null) {
            $this->recordInInbox($contract, $author, $message);
        }

        $author->notify(new ContractProgress($contract, $kind, $signer, $downloadUrl));
    }

    /**
     * Write the line in whichever conversation this belongs in, or nowhere.
     *
     * Nowhere is an ordinary answer rather than a failure: a contract sent to
     * four strangers with no channel named has no chat to appear in, and the
     * mail is then the whole of the notification. That is why this returns null
     * rather than throwing.
     */
    private function postToChat(
        Contract $contract,
        User $author,
        ContractProgressKind $kind,
        ?ContractSigner $signer,
    ): ?Message {
        $channel = $this->destination($contract, $author, $signer);

        if ($channel === null) {
            return null;
        }

        return $this->sendMessage->fromSystem($channel, $this->body($contract, $kind, $signer), self::BOT_NAME);
    }

    /**
     * Where the line goes.
     *
     * The conversation with the signer comes first, exactly as it does for a
     * form's answers: it is the one place where the reply the author may want
     * to write — "dank je, ik stuur de factuur" — is already addressed to the
     * right person.
     *
     * Everything that conversation cannot carry falls to the channel the author
     * named. That is most contracts, because a contract's signers are usually
     * not members at all, and it is the reason that column exists.
     */
    private function destination(Contract $contract, User $author, ?ContractSigner $signer): ?Channel
    {
        $member = $signer?->user;

        if ($member !== null && ! $member->is($author)) {
            return $this->startDirectMessage->handle($contract->workspace, $member, $author);
        }

        $channel = $contract->notifyChannel;

        if ($channel === null) {
            return null;
        }

        /*
         * A channel from another workspace is not a delivery, it is a leak: the
         * id was picked when the contract was sent and the channel may have
         * moved since. An archived one is refused for the softer reason that
         * nobody reads it — the same two judgements SendFormAnswers makes.
         */
        if ($channel->workspace_id !== $contract->workspace_id || $channel->archived_at !== null) {
            return null;
        }

        return $channel;
    }

    /**
     * What the bot writes.
     *
     * The URL is on its own line, and it is the contract's own address rather
     * than a download link. Two reasons, and the second is the one that
     * matters: this line is read by everybody in the channel, and the signed
     * PDF is not for all of them — while the contract's own address decides per
     * reader where it takes them. The other is that the link is what the card
     * grows out of, so the message reads as a card rather than as a URL.
     */
    private function body(Contract $contract, ContractProgressKind $kind, ?ContractSigner $signer): string
    {
        $line = match ($kind) {
            ContractProgressKind::Signed => __('contracts.chat.signed', [
                'name' => $signer->name ?? '',
                'title' => $contract->title,
            ]),
            ContractProgressKind::Declined => __('contracts.chat.declined', [
                'name' => $signer->name ?? '',
                'title' => $contract->title,
            ]),
            ContractProgressKind::Completed => trans_choice(
                'contracts.chat.completed',
                $contract->signers->count(),
                ['title' => $contract->title],
            ),
        };

        return $line."\n".route('chat.contracts.show', [$contract->workspace, $contract]);
    }

    /**
     * The row that makes the badge appear.
     *
     * updateOrCreate against the contract's own type and the author, so a
     * contract signed by four people over a week is one line in the inbox
     * rather than four. What it points at is the newest message, which is the
     * one worth landing on — and read_at is cleared, because news that arrived
     * after somebody marked the row off is news they have not seen.
     */
    private function recordInInbox(Contract $contract, User $author, Message $message): void
    {
        if (! $message->channel->members()->whereKey($author->id)->exists()) {
            return;
        }

        InboxItem::updateOrCreate([
            'type' => InboxItemType::ContractProgress,
            'user_id' => $author->id,
            'channel_id' => $message->channel_id,
        ], [
            'message_id' => $message->id,
            /*
             * Left empty, as a poll's row is. This line stands for a contract
             * rather than for one person's action, and naming the most recent
             * signer on a row that may speak for four would be putting one name
             * on everybody's news.
             */
            'actor_id' => null,
            'read_at' => null,
        ]);

        $this->announceInbox->handle($contract->workspace_id, [$author->id]);
    }
}
