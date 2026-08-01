<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Keeping a channel at the top of your own sidebar.
 *
 * The same shape as muting, and for the same reason: how you order your work is
 * yours, not the channel's. Nobody else sees it and nobody else can change it,
 * so being in the channel is the whole of the permission.
 */
class ChannelFavoriteController extends Controller
{
    public function store(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $this->update($request, $workspace, $channel, now());

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $this->update($request, $workspace, $channel, null);

        return back();
    }

    /** @param  CarbonInterface|null  $favoritedAt  Null takes it off the list. */
    private function update(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ?CarbonInterface $favoritedAt,
    ): void {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $user = $request->user();

        abort_unless($channel->members()->whereKey($user->id)->exists(), 403);

        $channel->members()->updateExistingPivot($user->id, [
            'favorited_at' => $favoritedAt,
        ]);
    }
}
