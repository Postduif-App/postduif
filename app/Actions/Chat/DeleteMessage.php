<?php

namespace App\Actions\Chat;

use App\Events\MessageDeleted;
use App\Models\Mention;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class DeleteMessage
{
    /**
     * Take a message off the channel and clean up everything derived from it.
     *
     * Soft delete rather than a real one: a message with replies has to leave a
     * marker behind, otherwise its thread — other people's words — becomes
     * unreachable. What hangs off the message and has no meaning without it
     * does go for good.
     */
    public function handle(Message $message): void
    {
        DB::transaction(function () use ($message) {
            // A badge for a message nobody can open any more is worse than no
            // badge at all, so the mentions go rather than being marked read.
            Mention::query()->where('message_id', $message->id)->delete();
            $message->reactions()->delete();

            // A pin points at words, and there are none left. Leaving it would
            // put a tombstone in the pin bar — the one place in the channel
            // where an empty row cannot be scrolled past.
            $message->unpin();

            $message->delete();

            if ($message->parent_id !== null) {
                $this->recountParent($message);
            }

            MessageDeleted::dispatch(
                $message,
                // A parent that keeps its replies keeps a tombstone. Anything
                // else disappears without trace.
                tombstone: $message->reply_count > 0,
            );
        });
    }

    /**
     * Recompute the parent's reply total from the replies that are left.
     *
     * Counting rather than decrementing: a count cannot end up negative or
     * drift, and this runs once per delete — not per keystroke.
     */
    private function recountParent(Message $message): void
    {
        $parent = Message::withTrashed()->find($message->parent_id);

        if ($parent === null) {
            return;
        }

        $parent->forceFill([
            'reply_count' => $parent->replies()->count(),
            'last_reply_at' => $parent->replies()->max('created_at'),
        ])->save();
    }
}
