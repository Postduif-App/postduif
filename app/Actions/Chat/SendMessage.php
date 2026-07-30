<?php

namespace App\Actions\Chat;

use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SendMessage
{
    public function __construct(
        private readonly RecordMentions $recordMentions,
        private readonly MarkChannelRead $markChannelRead,
    ) {}

    /**
     * Persist a message and keep the denormalised counters in step.
     *
     * The caller may supply the ULID so the browser can render the message
     * optimistically and recognise its own echo when it arrives over the
     * websocket. Anything else would show the message twice.
     */
    public function handle(
        Channel $channel,
        User $author,
        string $body,
        ?string $parentId = null,
        ?string $id = null,
    ): Message {
        return DB::transaction(function () use ($channel, $author, $body, $parentId, $id) {
            $message = Message::create([
                'id' => $id,
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'user_id' => $author->id,
                'parent_id' => $parentId,
                'body' => $body,
            ]);

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
            // for it.
            $this->markChannelRead->handle($channel, $author, $message->id);

            $this->notifyMembers($channel, $author, $parentId !== null, $mentioned);

            $message->load('author');

            // Broadcast to everyone on the channel, the sender included: the
            // browser recognises its own message by the ULID it minted, so a
            // duplicate is impossible and no socket id needs threading through.
            MessageSent::dispatch($message);

            return $message;
        });
    }

    /**
     * Nudge everyone else's sidebar. The message itself goes out on the
     * channel's presence socket, but only people with that channel open are
     * listening there — this reaches the rest.
     *
     * @param  Collection<int, int>  $mentioned
     */
    private function notifyMembers(
        Channel $channel,
        User $author,
        bool $isReply,
        Collection $mentioned,
    ): void {
        $recipients = $channel->members()
            ->whereKeyNot($author->id)
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
