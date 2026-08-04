<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Which channels this token can reach.
 *
 * Here because posting needs an id and there was nowhere to get one: the chat
 * screen does not show ids, and the MCP tool that finds them needs an AI client
 * to call it. An endpoint that can only be used by somebody who already knows
 * the answer is not much of an endpoint.
 */
class ChannelController extends Controller
{
    /** Enough to choose from without turning an answer into a directory. */
    private const LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));

        // The same gate the posting endpoint applies, so this list never offers
        // a channel that the next call would refuse.
        $open = $user->workspacesOpenToAi()->pluck('id');

        $channels = Channel::query()
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->with('workspace')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            /*
             * Membership is already implied by visibleTo, but saying the
             * workspace out loud here means a workspace this member was removed
             * from cannot surface through a stale row.
             */
            ->filter(fn (Channel $channel): bool => $channel->workspace instanceof Workspace
                && $open->contains($channel->workspace->id))
            ->values();

        return ChannelResource::collection($channels);
    }
}
