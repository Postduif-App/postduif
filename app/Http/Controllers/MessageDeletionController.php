<?php

namespace App\Http\Controllers;

use App\Actions\Chat\DeleteMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MessageDeletionController extends Controller
{
    /**
     * Remove one of your own messages.
     *
     * The route binds {message} scoped to the channel, so a message from
     * somewhere else is a 404 before this method runs.
     */
    public function __invoke(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        DeleteMessage $deleteMessage,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('delete', $message);

        $deleteMessage->handle($message);

        return back();
    }
}
