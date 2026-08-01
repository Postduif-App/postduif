<?php

namespace App\Actions\Chat;

use App\Events\ChannelActivity;
use App\Events\MessageEdited;
use App\Models\Mention;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class EditMessage
{
    public function __construct(private readonly RecordMentions $recordMentions) {}

    /**
     * Replace what a message says, and everything derived from what it said.
     *
     * A message is not only its text: mentions were recorded from it, and those
     * rows are what unread badges are counted from. Rewriting the body without
     * revisiting them leaves somebody notified about a mention that is no
     * longer in the message, or silently un-mentioned while their badge stays.
     */
    public function handle(Message $message, string $body): Message
    {
        return DB::transaction(function () use ($message, $body) {
            $message->forceFill([
                'body' => $body,
                'edited_at' => now(),
            ])->save();

            $this->resyncMentions($message);

            $message->load(['author', 'quoted.author']);

            MessageEdited::dispatch($message);

            return $message;
        });
    }

    /**
     * Bring the mention rows in line with the new text.
     *
     * Recorded first, pruned second, and in that order on purpose: RecordMentions
     * uses firstOrCreate, so whoever is still mentioned keeps the row they
     * already had — read state and all. Deleting everything first and starting
     * over would mark a mention somebody read this morning as new again on every
     * typo fix.
     */
    private function resyncMentions(Message $message): void
    {
        $before = Mention::query()
            ->where('message_id', $message->id)
            ->pluck('user_id');

        $mentioned = $this->recordMentions->handle($message)->pluck('id');

        Mention::query()
            ->where('message_id', $message->id)
            ->whereNotIn('user_id', $mentioned)
            ->delete();

        // Only the people the edit added. Someone who was already mentioned has
        // already been told, and telling them again would make an edit a way to
        // ping the same person repeatedly.
        foreach ($mentioned->diff($before) as $userId) {
            ChannelActivity::dispatch(
                $userId,
                $message->channel_id,
                $message->parent_id !== null,
                mentioned: true,
            );
        }
    }
}
