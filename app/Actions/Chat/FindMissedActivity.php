<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @phpstan-type MissedChannel array{channelId: int, label: string, unread: int, mentions: int, newestId: string}
 */
class FindMissedActivity
{
    public function __construct(private readonly ChannelPresence $presence) {}

    /**
     * What has happened in this member's channels while they were not there.
     *
     * "Not there" is two questions. The first is the read stamp: they have not
     * opened this channel for as long as they asked to be left alone. The
     * second is presence, because a tab that has been open for three hours
     * moves no stamp — without it, sitting in a channel all afternoon would be
     * indistinguishable from having gone home.
     *
     * Only counts what is new since both pointers: what they have read, and
     * what they were already told about. Muted and archived channels are out,
     * and so are their own messages — nobody needs telling what they said
     * themselves.
     *
     * @return Collection<int, array{workspace: Workspace, channels: Collection<int, MissedChannel>}>
     */
    public function handle(User $user, ?Carbon $now = null): Collection
    {
        if (! $user->wantsAbsenceNotifications()) {
            return new Collection;
        }

        $now ??= Carbon::now();
        $absentSince = $now->copy()->subMinutes($user->notify_after_minutes);

        $rows = $this->candidates($user, $absentSince);

        if ($rows->isEmpty()) {
            return new Collection;
        }

        $channels = Channel::with(['workspace', 'members'])
            ->whereIn('id', $rows->pluck('channelId'))
            ->get()
            ->keyBy('id');

        $mentions = $this->mentionCounts($user, $rows->pluck('channelId'));

        return $rows
            ->reject(fn (array $row): bool => $this->isWatching($user, $channels[$row['channelId']]))
            ->groupBy(fn (array $row): int => $channels[$row['channelId']]->workspace_id)
            ->map(fn (Collection $group) => [
                'workspace' => $channels[$group->first()['channelId']]->workspace,
                'channels' => $group
                    ->map(fn (array $row): array => [
                        'channelId' => $row['channelId'],
                        'label' => $this->labelFor($channels[$row['channelId']], $user),
                        'unread' => $row['unread'],
                        'mentions' => $mentions[$row['channelId']] ?? 0,
                        'newestId' => $row['newestId'],
                    ])
                    // Busiest first, but a channel that named them outranks a
                    // busier one that did not: being addressed is the half
                    // somebody actually has to act on.
                    ->sortByDesc(fn (array $channel): array => [$channel['mentions'], $channel['unread']])
                    ->values(),
            ])
            ->values();
    }

    /**
     * One row per channel with something to report: how much, and the newest
     * message in it.
     *
     * Mapped to an array shape here rather than handed on as models. These rows
     * are aggregates — a count and a max — that happen to be built through the
     * Message builder; reading them back as though they were message properties
     * is what makes the type unknowable. Naming the three of them at the
     * boundary is both honest and what the caller works with anyway.
     *
     * @return Collection<int, array{channelId: int, unread: int, newestId: string}>
     */
    private function candidates(User $user, Carbon $absentSince): Collection
    {
        return Message::query()
            ->join('channel_user', function ($join) use ($user) {
                $join->on('channel_user.channel_id', '=', 'messages.channel_id')
                    ->where('channel_user.user_id', '=', $user->id);
            })
            ->join('channels', 'channels.id', '=', 'messages.channel_id')
            ->whereNull('channels.archived_at')
            /*
             * Not muted, in the same sense as ChannelMembership::isMuted():
             * either it was never muted, or the mute has run out. Spelled out
             * because a null end date means "no end" rather than "expired".
             */
            ->where(fn ($query) => $query
                ->whereNull('channel_user.muted_at')
                ->orWhere(fn ($query) => $query
                    ->whereNotNull('channel_user.muted_until')
                    ->where('channel_user.muted_until', '<=', now())))
            // Never opened counts as away: somebody added to a channel who has
            // not looked at it yet is exactly who this is for.
            ->where(fn ($query) => $query
                ->whereNull('channel_user.last_read_at')
                ->orWhere('channel_user.last_read_at', '<=', $absentSince))
            ->whereNull('messages.parent_id')
            ->whereNull('messages.deleted_at')
            // IS DISTINCT FROM rather than !=, because a webhook leaves user_id
            // null and "null != 5" is null in SQL rather than true — which would
            // drop every bot message out of the count.
            ->whereRaw('messages.user_id IS DISTINCT FROM ?', [$user->id])
            // Past both pointers. GREATEST ignores nulls in Postgres, and the
            // empty string sorts below every ULID, so a membership that has read
            // nothing and been told nothing still matches everything.
            ->whereRaw("messages.id > COALESCE(GREATEST(channel_user.last_read_message_id, channel_user.last_notified_message_id), '')")
            ->groupBy('messages.channel_id')
            ->selectRaw('messages.channel_id, count(*) as unread, max(messages.id) as newest_id')
            ->get()
            ->map(fn (Message $row): array => [
                'channelId' => (int) $row->getAttribute('channel_id'),
                'unread' => (int) $row->getAttribute('unread'),
                'newestId' => (string) $row->getAttribute('newest_id'),
            ]);
    }

    /**
     * @param  Collection<int, mixed>  $channelIds
     * @return array<int, int>
     */
    private function mentionCounts(User $user, Collection $channelIds): array
    {
        return Mention::query()
            ->where('user_id', $user->id)
            ->unread()
            ->whereIn('channel_id', $channelIds)
            ->groupBy('channel_id')
            ->selectRaw('channel_id, count(*) as total')
            ->pluck('total', 'channel_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * Whether the member has this channel open right now.
     *
     * Asked per candidate channel rather than for everything: by this point the
     * list is the handful of channels that would otherwise be reported, so the
     * websocket server is asked a few times rather than once per membership.
     */
    private function isWatching(User $user, Channel $channel): bool
    {
        return $this->presence->handle($channel)->contains($user->id);
    }

    private function labelFor(Channel $channel, User $user): string
    {
        return $channel->isDirect()
            ? $channel->displayNameFor($user)
            : '#'.$channel->name;
    }
}
