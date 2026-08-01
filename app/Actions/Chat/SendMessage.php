<?php

namespace App\Actions\Chat;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SendMessage
{
    public function __construct(
        private readonly RecordMentions $recordMentions,
        private readonly MarkChannelRead $markChannelRead,
        private readonly QueueLinkPreviews $queueLinkPreviews,
    ) {}

    /**
     * Persist a message and keep the denormalised counters in step.
     *
     * The caller may supply the ULID so the browser can render the message
     * optimistically and recognise its own echo when it arrives over the
     * websocket. Anything else would show the message twice.
     *
     * @param  array<int, UploadedFile>  $attachments  Files sent along with it,
     *                                                 already judged against the workspace's settings — see
     *                                                 StoreMessageRequest.
     */
    public function handle(
        Channel $channel,
        User $author,
        string $body,
        ?string $parentId = null,
        ?string $id = null,
        ?string $quotedId = null,
        array $attachments = [],
    ): Message {
        return $this->post(
            $channel,
            ['user_id' => $author->id],
            $author,
            $body,
            $parentId,
            $id,
            $quotedId,
            $attachments,
        );
    }

    /**
     * Post through an incoming webhook.
     *
     * The bot name is copied onto the message instead of read back from the
     * webhook, so renaming the webhook later says nothing about what it has
     * already said.
     */
    public function fromWebhook(
        Webhook $webhook,
        string $body,
        ?string $parentId = null,
        ?string $id = null,
    ): Message {
        return $this->post(
            $webhook->channel,
            ['webhook_id' => $webhook->id, 'bot_name' => $webhook->bot_name],
            null,
            $body,
            $parentId,
            $id,
            null,
        );
    }

    /**
     * Post as the application itself, with no webhook behind it.
     *
     * The bot columns carry it: bot_name is what tells a bot message from a
     * member's, and webhook_id stays null because there is no integration to
     * point at. Used where the app has something to say in a channel — a ticket
     * that was opened or closed — which is a bot message in every way that
     * matters to a reader.
     */
    public function fromSystem(Channel $channel, string $body, string $botName): Message
    {
        return $this->post($channel, ['bot_name' => $botName], null, $body, null, null, null);
    }

    /**
     * @param  array<string, mixed>  $author  The columns identifying the sender:
     *                                        either a user_id, or a webhook_id
     *                                        plus the bot name.
     * @param  User|null  $member  The sender when it is a person. Null for a
     *                             webhook, which has no read state to advance
     *                             and nobody to leave out of the notifications.
     * @param  string|null  $quotedId  An older message in this channel that the
     *                                 new one answers. Independent of $parentId:
     *                                 a quote stays in the channel, and a thread
     *                                 reply may quote something too.
     * @param  array<int, UploadedFile>  $attachments  Empty for everything that
     *                                                 is not a person typing: a webhook and the app itself send
     *                                                 words, not files.
     */
    private function post(
        Channel $channel,
        array $author,
        ?User $member,
        string $body,
        ?string $parentId,
        ?string $id,
        ?string $quotedId,
        array $attachments = [],
    ): Message {
        return DB::transaction(function () use ($channel, $author, $member, $body, $parentId, $id, $quotedId, $attachments) {
            $message = Message::create([
                'id' => $id,
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'parent_id' => $parentId,
                'quoted_message_id' => $quotedId,
                'body' => $body,
                ...$author,
            ]);

            /*
             * Before the counters and the broadcast, so a file that cannot be
             * stored takes the whole message down with it rather than leaving
             * an empty line in the channel that says nothing.
             *
             * The file itself is written outside the database's reach, so a
             * later rollback leaves it on disk with no row pointing at it. That
             * is an orphan taking up space, not a leak — nothing can reach it.
             */
            foreach ($attachments as $attachment) {
                $message->addMedia($attachment)->toMediaCollection(Message::ATTACHMENTS);
            }

            $channel->forceFill(['last_message_at' => $message->created_at])->save();

            if ($parentId !== null) {
                Message::whereKey($parentId)->update([
                    'reply_count' => DB::raw('reply_count + 1'),
                    'last_reply_at' => $message->created_at,
                ]);
            }

            $mentioned = $this->recordMentions->handle($message)->pluck('id');

            // Posting is reading: the author has obviously seen everything up
            // to and including their own message, so never show them a badge
            // for it. A webhook reads nothing and has no badge to suppress.
            if ($member !== null) {
                $this->markChannelRead->handle($channel, $member, $message->id);
            }

            $this->notifyMembers($channel, $member, $parentId !== null, $mentioned);

            $message->load(['author', 'quoted.author']);

            // Broadcast to everyone on the channel, the sender included: the
            // browser recognises its own message by the ULID it minted, so a
            // duplicate is impossible and no socket id needs threading through.
            MessageSent::dispatch($message);

            /*
             * Last, and only as a queued job. Whether a link turns out to be
             * readable has no bearing on whether the message was sent, and the
             * conversation must never wait on somebody else's server.
             */
            $this->queueLinkPreviews->handle($message);

            return $message;
        });
    }

    /**
     * Nudge everyone else's sidebar. The message itself goes out on the
     * channel's presence socket, but only people with that channel open are
     * listening there — this reaches the rest.
     *
     * @param  User|null  $member  Left out of the recipients, because nobody
     *                             needs a badge for what they just typed. A
     *                             webhook excludes nobody: everyone in the
     *                             channel is a bystander to it.
     * @param  Collection<int, int>  $mentioned
     */
    private function notifyMembers(
        Channel $channel,
        ?User $member,
        bool $isReply,
        Collection $mentioned,
    ): void {
        $recipients = $channel->members()
            ->when($member, fn ($members) => $members->whereKeyNot($member->id))
            ->pluck('users.id');

        foreach ($recipients as $userId) {
            ChannelActivity::dispatch(
                $userId,
                $channel->id,
                $isReply,
                $mentioned->contains($userId),
            );
        }
    }
}
