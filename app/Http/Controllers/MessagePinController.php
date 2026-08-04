<?php

namespace App\Http\Controllers;

use App\Events\MessagePinned;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessagePinController extends Controller
{
    /**
     * How many messages one channel may keep pinned.
     *
     * A ceiling rather than an endless list: everything in the bar is meant to
     * be read by someone who just walked in, and a list of thirty is a list
     * nobody reads. Refused with a message instead of quietly dropping the
     * oldest — a pin that silently disappears is worse than one that never went
     * up.
     */
    private const MAX_PINS = 10;

    /**
     * Pin a message to the top of its channel.
     *
     * The route binds {message} scoped to the channel, so a message from
     * somewhere else is a 404 before this method runs.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('pin', $message);

        if (! $message->isPinned()) {
            $this->guardAgainstOverflow($channel);
        }

        $message->pin($request->user());

        MessagePinned::dispatch($message);

        return back();
    }

    /**
     * Take it back down.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('pin', $message);

        $message->unpin();

        MessagePinned::dispatch($message);

        return back();
    }

    private function guardAgainstOverflow(Channel $channel): void
    {
        $pinned = $channel->messages()->pinned()->count();

        if ($pinned < self::MAX_PINS) {
            return;
        }

        throw ValidationException::withMessages([
            'pin' => __('requests.message.too_many_pinned', ['count' => self::MAX_PINS]),
        ]);
    }
}
