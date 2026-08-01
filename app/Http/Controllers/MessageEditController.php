<?php

namespace App\Http\Controllers;

use App\Actions\Chat\EditMessage;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class MessageEditController extends Controller
{
    /**
     * Rewrite one of your own messages.
     *
     * A sibling of MessageDeletionController rather than a method on
     * MessageController: what you may do to a message that already exists is a
     * different question from what you may post, and the two have different
     * answers — a channel only admins may post in still lets everyone fix a
     * typo in what they said before the rule changed.
     */
    public function __invoke(
        UpdateMessageRequest $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        EditMessage $editMessage,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $editMessage->handle($message, $request->string('body')->value());

        return back();
    }
}
