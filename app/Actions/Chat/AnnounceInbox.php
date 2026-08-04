<?php

namespace App\Actions\Chat;

use App\Events\InboxUpdated;
use App\Models\Channel;
use App\Models\InboxItem;
use Illuminate\Support\Collection;

class AnnounceInbox
{
    /**
     * Tell these members what is waiting for them in this workspace.
     *
     * Called after rows are written rather than from a model observer: the
     * writes happen in loops, and one event per row would send four messages to
     * say one thing. Handing it the recipients lets it send one each.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     */
    public function handle(int $workspaceId, Collection|array $userIds): void
    {
        $recipients = Collection::make($userIds)->unique()->values();

        if ($recipients->isEmpty()) {
            return;
        }

        /*
         * Scoped through the channels rather than off a workspace column,
         * because a row has none — it belongs to a channel, and the channel
         * knows the workspace. One subquery on an indexed column, against a
         * count this member's own index already answers.
         */
        $channels = Channel::query()
            ->where('workspace_id', $workspaceId)
            ->select('id');

        $counts = InboxItem::query()
            ->whereIn('user_id', $recipients->all())
            ->whereIn('channel_id', $channels)
            ->unread()
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as total')
            ->pluck('total', 'user_id');

        foreach ($recipients as $userId) {
            // Zero is worth sending: it is what somebody sees when the last
            // thing waiting for them was just taken away.
            InboxUpdated::dispatch($userId, $workspaceId, (int) ($counts[$userId] ?? 0));
        }
    }
}
