<?php

namespace App\Actions\Chat;

use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Put a reply in the inbox of everyone the thread belongs to.
 *
 * Separate from RecordMentions because the two ask different questions. A
 * mention is in the text and has to be parsed out of it; a thread is in the
 * shape of the conversation and is already known the moment the row is written.
 *
 * Two kinds come out of here, and the difference is worth keeping. Being
 * answered is a question put to you; a thread you once spoke in carrying on is
 * news. Everyone gets at most one row, and always the strongest one that
 * applies — mention over reply, reply over thread.
 *
 * On closed threads: thread_user records that somebody said "I'm done here",
 * but SendMessage has already moved the parent's last_reply_at by the time this
 * runs, so by the rule FindActiveThreads uses the closure is undone the moment
 * anything new is said. That is deliberate rather than overlooked — a thread
 * that comes back into the sidebar and stays silent in the inbox would be the
 * same thread telling two different stories. Muting a thread outright is a
 * different feature, and wants its own column.
 */
class RecordThreadInbox
{
    public function __construct(private readonly AnnounceInbox $announceInbox) {}

    /**
     * Everyone written to during this call, so the badge is announced once.
     *
     * @var Collection<int, int>
     */
    private Collection $told;

    /**
     * @param  Collection<int, int>  $mentioned  Already getting a row for this
     *                                           message, so they must not get a
     *                                           second one saying the same thing
     *                                           in weaker words.
     */
    public function handle(Message $reply, Collection $mentioned): void
    {
        if ($reply->parent_id === null) {
            return;
        }

        $parent = Message::query()
            ->select(['id', 'user_id', 'channel_id'])
            ->find($reply->parent_id);

        if ($parent === null) {
            return;
        }

        $this->told = new Collection;

        $spoken = $this->addressed($parent, $reply, $mentioned);

        $this->write(InboxItemType::ThreadReply, $parent, $reply, $this->participants(
            $parent,
            $reply,
            $spoken,
        ));

        // Once for everybody, after both kinds are written: a member who is in
        // this thread twice over should not see the number move twice.
        $this->announceInbox->handle($reply->workspace_id, $this->told);
    }

    /**
     * The thread starter, told that they were answered, and returned along with
     * everyone who must not hear it a second time.
     *
     * @param  Collection<int, int>  $mentioned
     * @return Collection<int, int>
     */
    private function addressed(Message $parent, Message $reply, Collection $mentioned): Collection
    {
        // Answering yourself is not news. A webhook's parent has no member
        // behind it, and neither has anybody to tell.
        $answered = $parent->user_id !== null
            && $parent->user_id !== $reply->user_id
            && ! $mentioned->contains($parent->user_id);

        if ($answered) {
            $this->write(InboxItemType::Reply, $parent, $reply, $this->eligible(
                $parent,
                $reply,
                new Collection([$parent->user_id]),
            ));
        }

        // merge() rather than push(): push mutates, and this collection belongs
        // to SendMessage, which still has its own use for it.
        return $mentioned
            ->merge([$parent->user_id, $reply->user_id])
            ->reject(fn (?int $id): bool => $id === null)
            ->unique()
            ->values();
    }

    /**
     * Everyone who has spoken in this thread and has not already been told.
     *
     * @param  Collection<int, int>  $spoken
     * @return Collection<int, int>
     */
    private function participants(Message $parent, Message $reply, Collection $spoken): Collection
    {
        // The parent and its replies in one pass. A thread is one level deep,
        // so there is nothing below this to walk.
        $ids = Message::query()
            ->where('parent_id', $parent->id)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->reject(fn (int $id): bool => $spoken->contains($id))
            ->values();

        return $this->eligible($parent, $reply, $ids);
    }

    /**
     * Narrowed to people who can still open the channel and want to hear about
     * this thread at all.
     *
     * Two queries for the whole set rather than two per recipient: a lively
     * thread has as many participants as it has replies, and this runs inside
     * the transaction that is holding up the message.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function eligible(Message $parent, Message $reply, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return $ids;
        }

        $members = $reply->channel->members()
            ->whereIn('users.id', $ids->all())
            ->pluck('users.id');

        if ($members->isEmpty()) {
            return $members;
        }

        /*
         * Muting is the one thing here that a new reply does not undo — which
         * is precisely what separates it from closing, and why it is read off
         * its own column. See Message::muteFor.
         */
        $muted = DB::table('thread_user')
            ->where('message_id', $parent->id)
            ->whereIn('user_id', $members->all())
            ->whereNotNull('muted_at')
            ->pluck('user_id');

        return $members->reject(fn (int $id): bool => $muted->contains($id))->values();
    }

    /**
     * @param  Collection<int, int>  $recipients
     */
    private function write(
        InboxItemType $type,
        Message $parent,
        Message $reply,
        Collection $recipients,
    ): void {
        foreach ($recipients as $userId) {
            $this->told->push($userId);

            /*
             * Keyed on the parent rather than on this reply, which is what
             * makes twenty answers a single line. read_at goes back to null on
             * every bump: the row has something in it that was not there when
             * it was last opened, so it has not been read.
             */
            InboxItem::updateOrCreate([
                'type' => $type,
                'message_id' => $parent->id,
                'user_id' => $userId,
            ], [
                'channel_id' => $parent->channel_id,
                'actor_id' => $reply->isFromBot() ? null : $reply->user_id,
                'read_at' => null,
            ]);
        }
    }
}
