<?php

namespace App\Http\Controllers;

use App\Actions\Huddles\JoinHuddle;
use App\Actions\Huddles\LeaveHuddle;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Getting into the conversation in a channel, and out of it again.
 *
 * Two endpoints and no third to start one: pressing the button means "put me in
 * the huddle here", and whether that makes one or joins one is the channel's
 * business — see JoinHuddle.
 *
 * Nothing here carries audio or knows how a connection is made. That happens
 * between the browsers, over the presence channel they are already on; what
 * these two do is agree on who is in the room.
 */
class HuddleController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        JoinHuddle $joinHuddle,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('join', [Huddle::class, $channel]);

        $joinHuddle->handle($channel, $request->user());

        return back();
    }

    /**
     * Still here.
     *
     * Every browser in a huddle says this while it is in one, and whoever stops
     * saying it is swept — see SweepStaleHuddles. It is the only way a huddle
     * can find out about a browser that crashed, because a crashed browser
     * sends nothing by definition.
     *
     * Deliberately cheap: one indexed update, no broadcast, no props. It runs
     * every half minute per person in every huddle, so anything it did beyond
     * this would be paid for over and over.
     */
    public function update(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Huddle $huddle,
    ): Response {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $huddle->present()
            ->where('user_id', $request->user()->id)
            ->update(['last_seen_at' => now()]);

        return response()->noContent();
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Huddle $huddle,
        LeaveHuddle $leaveHuddle,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        /*
         * Not authorised but simply done: leaving something you are not in is
         * already true, and a 403 for it would turn a stale tab into an error
         * message. What the policy does guard is that it is your own place in
         * the huddle you are giving up, and no request here can name anybody
         * else's.
         */
        $leaveHuddle->handle($huddle, $request->user());

        return back();
    }
}
