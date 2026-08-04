<?php

namespace App\Actions\Chat;

use App\Enums\InboxItemType;
use App\Events\ChannelActivity;
use App\Events\MessageEdited;
use App\Models\InboxItem;
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
     *
     * Both queries are narrowed to mentions, and that narrowing is load-bearing
     * rather than tidiness: a thread's replies hang their inbox rows off this
     * same message id, so an unscoped prune would let fixing a typo in the
     * opening post quietly empty everyone else's inbox.
     */
    private function resyncMentions(Message $message): void
    {
        $before = InboxItem::query()
            ->where('message_id', $message->id)
            ->ofType(InboxItemType::Mention)
            ->pluck('user_id');

        $mentioned = $this->recordMentions->handle($message)->pluck('id');

        InboxItem::query()
            ->where('message_id', $message->id)
            ->ofType(InboxItemType::Mention)
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
