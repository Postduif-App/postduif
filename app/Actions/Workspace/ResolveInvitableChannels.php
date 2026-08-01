<?php

namespace App\Actions\Workspace;

use App\Enums\ChannelType;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Keep only channels that are actually in this workspace and can be handed out.
 *
 * Shared by every way somebody is invited in — one address at a time through
 * InviteToWorkspace, or by a link anybody may use. Both take ids from a
 * browser, so the list is re-derived here rather than trusted, and both have to
 * answer the same: a check that lives in one of them and not the other is a way
 * in through the door that was not looked at.
 *
 * DMs are excluded because they are defined by who is in them, not by being
 * handed out; archived channels because there is nothing to join.
 */
class ResolveInvitableChannels
{
    /**
     * @param  Collection<int, int>|array<int, int>  $channelIds
     * @return array<int, int>
     */
    public function handle(Workspace $workspace, Collection|array $channelIds): array
    {
        $wanted = (new Collection($channelIds))->map(fn ($id): int => (int) $id)->unique();

        if ($wanted->isEmpty()) {
            return [];
        }

        return $workspace->channels()
            ->whereIn('id', $wanted)
            ->where('type', '!=', ChannelType::Direct)
            ->whereNull('archived_at')
            ->pluck('id')
            ->all();
    }
}
