<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ThreadMuteController extends Controller
{
    /**
     * Stop this thread reaching your inbox.
     *
     * Beside closing rather than instead of it, because the two say different
     * things. Closing means "done with this as it stands" and a new reply undoes
     * it; muting means "not again" and nothing undoes it but this controller.
     * Somebody who only ever wanted the sidebar tidied is still served by the
     * other one.
     *
     * Per member, like closing, and for the same reason: silencing a
     * conversation for everybody else is not a tidying-up gesture.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('view', $channel);

        $message->muteFor($request->user());

        return back();
    }

    /** Start hearing about it again. */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('view', $channel);

        $message->unmuteFor($request->user());

        return back();
    }
}
