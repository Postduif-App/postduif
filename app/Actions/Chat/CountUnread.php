<?php

namespace App\Actions\Chat;

use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

class CountUnread
{
    /**
     * Unread totals for a member across many channels, in two queries.
     *
     * Derived from each channel's read pointer rather than stored, so the
     * numbers cannot drift away from the messages they describe.
     *
     * @param  Collection<int, int>  $channelIds
     * @return array{unread: array<int, int>, mentions: array<int, int>}
     */
    public function handle(User $user, Collection $channelIds): array
    {
        if ($channelIds->isEmpty()) {
            return ['unread' => [], 'mentions' => []];
        }

        return [
            'unread' => $this->unreadMessages($user, $channelIds),
            'mentions' => $this->unreadMentions($user, $channelIds),
        ];
    }

    /**
     * Thread replies are deliberately excluded: in a channel list, a busy
     * thread should not make the channel itself look unread. The thread gets
     * its own indicator.
     *
     * @param  Collection<int, int>  $channelIds
     * @return array<int, int>
     */
    private function unreadMessages(User $user, Collection $channelIds): array
    {
        return Message::query()
            ->join('channel_user', function ($join) use ($user) {
                $join->on('channel_user.channel_id', '=', 'messages.channel_id')
                    ->where('channel_user.user_id', '=', $user->id);
            })
            ->whereIn('messages.channel_id', $channelIds)
            ->whereNull('messages.parent_id')
            ->whereNull('messages.deleted_at')
            ->where('messages.user_id', '!=', $user->id)
            ->where(function ($query) {
                $query->whereNull('channel_user.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'channel_user.last_read_message_id');
            })
            ->groupBy('messages.channel_id')
            ->selectRaw('messages.channel_id, count(*) as total')
            ->pluck('total', 'messages.channel_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  Collection<int, int>  $channelIds
     * @return array<int, int>
     */
    private function unreadMentions(User $user, Collection $channelIds): array
    {
        return Mention::query()
            ->where('user_id', $user->id)
            ->unread()
            ->whereIn('channel_id', $channelIds)
            ->groupBy('channel_id')
            ->selectRaw('channel_id, count(*) as total')
            ->pluck('total', 'channel_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
