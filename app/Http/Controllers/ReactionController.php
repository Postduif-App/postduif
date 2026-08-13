<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ToggleReaction;
use App\Http\Requests\StoreReactionRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class ReactionController extends Controller
{
    /**
     * Toggle one emoji on one message for the signed-in member.
     *
     * The route binds {message} scoped to the channel, so a message from
     * somewhere else is a 404 before this method runs.
     */
    public function store(
        StoreReactionRequest $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        ToggleReaction $toggleReaction,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        $toggleReaction->handle(
            message: $message,
            user: $request->user(),
            emoji: $request->string('emoji')->value(),
        );

        return back();
    }
}
