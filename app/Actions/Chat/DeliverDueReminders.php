<?php

namespace App\Actions\Chat;

use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\Reminder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeliverDueReminders
{
    public function __construct(private readonly AnnounceInbox $announceInbox) {}

    /**
     * Bring back everything whose moment has come.
     *
     * Each reminder is claimed before anything is written, in its own small
     * transaction: the schedule is not a promise to run once, and two sweeps
     * overlapping must not put the same message in somebody's inbox twice. The
     * claim is the update that fills delivered_at, and the row count it returns
     * is what says whether this run got there first.
     *
     * A reminder for a message somebody can no longer see is dropped rather
     * than delivered. Between setting one and it going off, a channel can be
     * left, a private channel can be closed, a share can be revoked — and an
     * inbox row pointing somewhere they cannot follow is worse than nothing:
     * it says a thing exists and refuses to show it.
     *
     * @return array{delivered: int, dropped: int}
     */
    public function handle(): array
    {
        $delivered = 0;
        $dropped = 0;

        /*
         * Who to tell afterwards, as workspace id => set of member ids. A
         * nested array keyed both ways rather than a list of pairs, so that
         * "this person, in this workspace, once" is the shape of the structure
         * instead of something a unique() has to work out later.
         *
         * @var array<int, array<int, true>> $notify
         */
        $notify = [];

        Reminder::query()
            ->due()
            ->with(['user', 'message', 'channel.workspace'])
            ->orderBy('remind_at')
            ->chunkById(200, function (Collection $reminders) use (&$delivered, &$dropped, &$notify): void {
                foreach ($reminders as $reminder) {
                    $outcome = $this->deliver($reminder);

                    if ($outcome === null) {
                        continue;
                    }

                    if ($outcome) {
                        $delivered++;
                        $notify[$reminder->channel->workspace_id][$reminder->user_id] = true;

                        continue;
                    }

                    $dropped++;
                }
            });

        /*
         * One announcement per person per workspace, after the whole sweep
         * rather than inside the loop. Somebody who set five reminders for nine
         * o'clock gets one badge update, which is the same reasoning
         * RecordMentions follows for a message that names four people.
         */
        foreach ($notify as $workspaceId => $userIds) {
            $this->announceInbox->handle($workspaceId, array_keys($userIds));
        }

        return ['delivered' => $delivered, 'dropped' => $dropped];
    }

    /**
     * One reminder: claim it, then either write the inbox row or drop it.
     *
     * Null means another run had already claimed it — nothing happened here and
     * nothing should be counted. True means it landed; false means the message
     * is out of reach and the reminder was retired quietly.
     */
    private function deliver(Reminder $reminder): ?bool
    {
        $claimed = DB::transaction(fn (): int => Reminder::query()
            ->whereKey($reminder->id)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]));

        if ($claimed === 0) {
            return null;
        }

        /*
         * A withdrawn message is gone from here: Message soft-deletes, so the
         * relation reads null the moment somebody takes theirs back. Sending
         * anybody to the space where it used to be would be the reminder
         * working exactly when it should not.
         */
        if ($reminder->message === null || $reminder->user === null) {
            return false;
        }

        if ($reminder->user->cannot('view', $reminder->channel)) {
            return false;
        }

        InboxItem::updateOrCreate([
            'user_id' => $reminder->user_id,
            'message_id' => $reminder->message_id,
            'type' => InboxItemType::Reminder,
        ], [
            'channel_id' => $reminder->channel_id,
            /*
             * No actor. Every other kind of inbox row is somebody doing
             * something to you; this one is you, earlier — and naming yourself
             * as the actor would make the inbox read "Jij noemde jou".
             */
            'actor_id' => null,
            // Cleared, so a reminder set on a message whose earlier row was
            // read still arrives as something waiting.
            'read_at' => null,
        ]);

        return true;
    }
}
