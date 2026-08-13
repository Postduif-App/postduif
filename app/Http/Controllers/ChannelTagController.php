<?php

namespace App\Http\Controllers;

use App\Actions\Chat\SyncChannelTags;
use App\Http\Requests\SyncChannelTagsRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * The labels on a channel.
 *
 * One endpoint that takes the whole set rather than an add and a remove: the
 * settings dialog knows what the channel should carry when it saves, and two
 * endpoints would mean a half-applied change whenever one of the two failed.
 */
class ChannelTagController extends Controller
{
    public function update(
        SyncChannelTagsRequest $request,
        Workspace $workspace,
        Channel $channel,
        SyncChannelTags $syncChannelTags,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        $syncChannelTags->handle($channel, $request->array('tags'));

        return back();
    }
}
