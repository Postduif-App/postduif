<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ForwardMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Carrying a message into another conversation.
 *
 * Two permissions, and both are needed: you may read where it comes from, and
 * you may post where it is going. Neither implies the other, and checking only
 * the second is how a private channel's words end up somewhere its members
 * never agreed to.
 */
class MessageForwardController extends Controller
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        ForwardMessage $forwardMessage,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($message->channel_id === $channel->id, 404);

        // A deleted message never gets this far: route binding leaves the
        // trashed ones out, so it is a 404 before this method runs.

        $this->authorize('view', $channel);

        $validated = $request->validate([
            'channel_id' => [
                'required',
                'integer',
                // Inside this workspace, so a forward can never cross into one
                // the member happens to belong to as well.
                Rule::exists('channels', 'id')->where('workspace_id', $workspace->id),
            ],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        // Narrowed by hand: findOrFail takes a list as well as one key, so with
        // an unknown type it could come back as a collection.
        $target = Channel::query()->whereKey((int) $validated['channel_id'])->firstOrFail();

        // Posting, not viewing: carrying somebody else's words somewhere is
        // still writing there, and a channel you may only read is not a place
        // you get to put things.
        $this->authorize('post', $target);

        $forwardMessage->handle($message, $target, $request->user(), $validated['note'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.channel.forwarded', ['name' => $target->name]),
        ]);

        return back();
    }
}
