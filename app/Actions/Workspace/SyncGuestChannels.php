<?php

namespace App\Actions\Workspace;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncGuestChannels
{
    /**
     * Set exactly which channels a guest belongs to.
     *
     * A whole list rather than one add and one remove: whoever administers a
     * guest is answering "where does this person belong", and sending that
     * answer in one request means two admins editing at once cannot end up
     * with a guest in a channel neither of them ticked.
     *
     * Ids from the request are intersected with the workspace's own channels
     * rather than trusted — the same reason AddChannelMembers re-derives its
     * candidates.
     *
     * Two kinds of membership survive a list that leaves them out, because
     * they are not this screen's to end: DMs, which are a conversation between
     * two people, and channels the guest created themselves back when they
     * could. Both are invisible here for the same reason.
     *
     * @param  Collection<int, int>|array<int, int>  $channelIds
     * @return array{added: int, removed: int}
     */
    public function handle(Workspace $workspace, User $guest, Collection|array $channelIds): array
    {
        $wanted = (new Collection($channelIds))->map(fn ($id): int => (int) $id)->unique();

        return DB::transaction(function () use ($workspace, $guest, $wanted): array {
            $manageable = $this->manageableChannels($workspace, $guest)->pluck('id');

            $keep = $manageable->intersect($wanted);
            $current = $guest->channels()->whereIn('channels.id', $manageable)->pluck('channels.id');

            $toRemove = $current->diff($keep);
            $toAdd = $keep->diff($current);

            if ($toRemove->isNotEmpty()) {
                $guest->channels()->detach($toRemove->all());
            }

            if ($toAdd->isNotEmpty()) {
                $guest->channels()->attach($toAdd->all(), ['joined_at' => now()]);
            }

            return ['added' => $toAdd->count(), 'removed' => $toRemove->count()];
        });
    }

    /**
     * The channels this screen may hand out: everything in the workspace that
     * is a real channel, still open, and not the guest's own.
     *
     * @return Collection<int, Channel>
     */
    public function manageableChannels(Workspace $workspace, User $guest): Collection
    {
        return $workspace->channels()
            ->where('type', '!=', ChannelType::Direct)
            ->whereNull('archived_at')
            ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $guest->id))
            ->orderBy('name')
            ->get();
    }
}
