<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FindActiveThreads
{
    /**
     * The threads a member should see in their sidebar right now.
     *
     * "Active" is decided by the thread's own clock — its last reply — and not
     * by the channel's, so a quiet channel with one lively thread still shows
     * that thread, which is the whole point of the section.
     *
     * One query. The sidebar is built on every page load, so a per-channel
     * lookup here would scale with the number of channels somebody is in.
     *
     * @return Collection<int, Message>
     */
    public function handle(User $user, Workspace $workspace): Collection
    {
        $cutoff = now()->subHours((int) config('chat.thread_window_hours'));

        return Message::query()
            ->where('messages.workspace_id', $workspace->id)
            ->whereNull('messages.parent_id')
            ->where('messages.reply_count', '>', 0)
            // A thread with replies always has last_reply_at, but coalescing
            // keeps a row that predates that bookkeeping from being read as
            // "active since the beginning of time".
            ->whereRaw('coalesce(messages.last_reply_at, messages.created_at) >= ?', [$cutoff])
            // A subquery rather than whereHas(): the relation is named by a
            // string, so nothing can work out which model the closure's builder
            // is over — and visibleTo() is a scope on channels. See
            // Ticket::scopeVisibleTo, which had the same problem.
            ->whereIn('messages.channel_id', Channel::query()
                ->visibleTo($user)
                ->whereNull('archived_at')
                ->select('id'))
            ->whereDoesntHave('closedBy', fn (Builder $reader) => $reader
                ->whereKey($user->id)
                // Closed *before* the last reply does not count: saying "I'm
                // done here" is about the conversation as it stood, so anything
                // said afterwards puts the thread back on the list.
                ->whereColumn('thread_user.closed_at', '>=', 'messages.last_reply_at'))
            ->visible()
            ->with('author')
            ->orderByRaw('coalesce(messages.last_reply_at, messages.created_at) desc')
            ->limit((int) config('chat.thread_limit'))
            ->get();
    }
}
