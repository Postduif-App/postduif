<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workflow;

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
     * The files come along as copies rather than as a second row pointing at
     * the same bytes. A shared file would be reachable through the original
     * message's route, which is scoped to a channel the reader may not be in —
     * and taking the original message back would take the forward's files with
     * it. Own copy, own message, own permission check.
     *
     * @param  Workflow|null  $workflow  The workflow doing the forwarding, where
     *                                   one is. Then the forwarder is still who
     *                                   the rights belong to, but the message is
     *                                   signed by the bot: nobody decided this
     *                                   belonged here, a rule did.
     */
    public function handle(
        Message $message,
        Channel $target,
        User $forwarder,
        ?string $note = null,
        ?Workflow $workflow = null,
    ): Message {
        $body = trim($note ?? '') === ''
            ? $message->body
            : trim($note)."\n\n".$message->body;

        $forwarded = $this->sendMessage->fromMemberOrWorkflow(
            channel: $target,
            member: $forwarder,
            body: $body,
            workflow: $workflow,
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

        $this->copyAttachments($message, $forwarded, $target);

        return $forwarded;
    }

    /**
     * Carry the files across, as copies.
     *
     * Judged against the workspace as it stands now rather than as it stood
     * when the file was uploaded: a workspace that has since switched sharing
     * off, or stopped taking that kind of file, means it now — and a forward is
     * a new message, not a replay of an old one. The words still go; only the
     * files stay behind.
     */
    private function copyAttachments(Message $message, Message $forwarded, Channel $target): void
    {
        $workspace = $target->workspace;

        foreach ($message->getMedia(Message::ATTACHMENTS) as $attachment) {
            if (! $workspace->acceptsAttachment((string) $attachment->mime_type)) {
                continue;
            }

            $attachment->copy($forwarded, Message::ATTACHMENTS);
        }
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
