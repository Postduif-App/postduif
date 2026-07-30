<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SendMessage
{
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

            return $message->load('author');
        });
    }
}
