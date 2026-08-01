<?php

namespace App\Http\Controllers;

use App\Models\Bookmark;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Setting a message aside for yourself, and taking it off the list again.
 *
 * Judged by whether you can read the channel, not by who wrote the message:
 * saving is a note to self about somebody else's words, which is exactly the
 * common case. Invisible to everyone else, so there is nothing to authorise
 * beyond being allowed to see it in the first place.
 */
class MessageBookmarkController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->ensureReadable($workspace, $channel, $message);

        // firstOrCreate rather than create: saving something twice is the same
        // act, and the unique index would otherwise turn a double click into an
        // error page.
        Bookmark::firstOrCreate([
            'user_id' => $request->user()->id,
            'message_id' => $message->id,
        ], [
            'channel_id' => $channel->id,
        ]);

        return back();
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        $this->ensureReadable($workspace, $channel, $message);

        Bookmark::query()
            ->where('user_id', $request->user()->id)
            ->where('message_id', $message->id)
            ->delete();

        return back();
    }

    private function ensureReadable(Workspace $workspace, Channel $channel, Message $message): void
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($message->channel_id === $channel->id, 404);

        $this->authorize('view', $channel);
    }
}
