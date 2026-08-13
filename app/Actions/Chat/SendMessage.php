<?php

namespace App\Actions\Chat;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SendMessage
{
    public function __construct(
        private readonly RecordMentions $recordMentions,
        private readonly RecordThreadInbox $recordThreadInbox,
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
     *
     * @param  Workflow|null  $workflow  The workflow this was posted by, where
     *                                   one was. Only ever read for its face —
     *                                   the name is copied into bot_name above,
     *                                   so a workflow deleted tomorrow leaves
     *                                   this message signed and unillustrated
     *                                   rather than unsigned. Null for the
     *                                   application speaking for itself, which
     *                                   has no picture to offer.
     * @param  string|null  $parentId  A message in this channel to hang the
     *                                 reply under. It has to travel through
     *                                 post() rather than be written on
     *                                 afterwards: the parent's reply counter,
     *                                 the thread inbox and the broadcast all
     *                                 depend on knowing this at the moment the
     *                                 message is made, and a parent set later
     *                                 produces a reply that exists in the
     *                                 database and in no thread anybody can see.
     */
    public function fromSystem(
        Channel $channel,
        string $body,
        string $botName,
        ?string $parentId = null,
        ?Workflow $workflow = null,
    ): Message {
        return $this->post(
            $channel,
            ['bot_name' => $botName, 'workflow_id' => $workflow?->id],
            null,
            $body,
            $parentId,
            null,
            null,
        );
    }

    /**
     * Post as the member, unless a workflow is doing it in their name.
     *
     * The one question every announcing action has to answer: a poll, a
     * contract, a secret request and a forward are all somebody saying
     * something in a channel, right up until a workflow is what set them off.
     * Then the rights are still the owner's — that is settled long before this
     * — but the name on the message must not be, because a colleague appearing
     * to say something they never said is not a thing to leave to whoever reads
     * carefully.
     *
     * Answered here rather than four times over, so an announcing action added
     * next year cannot be the one that forgot.
     *
     * @param  Workflow|null  $workflow  The workflow this is running inside, or
     *                                   null for a person doing it themselves.
     */
    public function fromMemberOrWorkflow(
        Channel $channel,
        User $member,
        string $body,
        ?Workflow $workflow = null,
    ): Message {
        if ($workflow === null) {
            return $this->handle($channel, $member, $body);
        }

        return $this->fromSystem($channel, $body, $workflow->botName(), workflow: $workflow);
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

            $this->recordThreadInbox->handle($message, $mentioned);

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
