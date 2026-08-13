<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThreadClosureController extends Controller
{
    /**
     * Take a thread out of your own sidebar.
     *
     * Per member on purpose: "I have read enough of this" is a statement about
     * yourself, and a thread that disappeared for everybody the moment one
     * person was done with it would be a way to end other people's
     * conversations.
     *
     * The route binds {message} scoped to the channel, so a message from
     * somewhere else is a 404 before this method runs. Reading the channel is
     * the only ability asked for: whether you may post here says nothing about
     * whether you may tidy up your own sidebar.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('view', $channel);

        $message->closeFor($request->user());

        return back();
    }

    /**
     * Put it back. Closing is not a decision anyone should have to live with,
     * and without this the only way back would be waiting for a new reply.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('view', $channel);

        $message->reopenFor($request->user());

        return back();
    }
}
