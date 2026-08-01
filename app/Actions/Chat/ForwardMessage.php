<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

class ForwardMessage
{
    public function __construct(private readonly SendMessage $sendMessage) {}

    /**
     * Carry what somebody said into another conversation.
     *
     * A new message rather than a pointer to the old one. A pointer would have
     * to resolve in a channel the reader may not be in, and then either break
     * for them or tell them the channel exists — so the words are copied, and
     * the only thing that travels with them is who said them first.
     *
     * The forwarder is the author of the new message, which is the honest
     * reading: they decided this belonged here. What the original author gets
     * is attribution, not authorship — they cannot edit or delete a copy they
     * did not place.
     *
     * Attachments deliberately stay behind. They live on a private disk behind
     * a route scoped to the original channel, so a copied file would be a link
     * the reader cannot open — see pcom-yvwn for carrying them along properly.
     */
    public function handle(
        Message $message,
        Channel $target,
        User $forwarder,
        ?string $note = null,
    ): Message {
        $body = trim($note ?? '') === ''
            ? $message->body
            : trim($note)."\n\n".$message->body;

        $forwarded = $this->sendMessage->handle(
            channel: $target,
            author: $forwarder,
            body: $body,
        );

        /*
         * Written after the fact rather than threaded through SendMessage: the
         * attribution is what a forward adds on top of an ordinary message, and
         * every other caller of that action would have to carry a parameter it
         * never uses.
         */
        $forwarded->forceFill([
            'forwarded_from' => $this->attribution($message),
        ])->save();

        return $forwarded;
    }

    /**
     * Whose words these were.
     *
     * A bot's name for a bot, the member's for a member, and a plain fallback
     * for an account that has since been removed — better than an attribution
     * that reads as though nobody said it.
     */
    private function attribution(Message $message): string
    {
        if ($message->isFromBot()) {
            return (string) $message->bot_name;
        }

        /*
         * The name straight off the users table, not through the relation.
         * user_id goes null when an account is removed, so the fallback is a
         * real case rather than defensive noise — and one column is all this
         * needs.
         */
        $name = $message->user_id === null
            ? null
            : User::query()->whereKey($message->user_id)->value('name');

        return $name === null ? 'een oud-collega' : (string) $name;
    }
}
