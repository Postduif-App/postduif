<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduledMessageRequest;
use App\Models\Channel;
use App\Models\ScheduledMessage;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Messages written now to be said later.
 *
 * Only writes live here; the overview of what is still to go out is read
 * through the chat page, the same way an open thread and a ticket are.
 */
class ScheduledMessageController extends Controller
{
    public function store(
        StoreScheduledMessageRequest $request,
        Workspace $workspace,
        Channel $channel,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        /*
         * Converted to UTC before it is stored, not merely parsed.
         *
         * The browser sends a real instant with its offset in it. Carbon keeps
         * that offset, and Eloquent writes the instance out as it stands — so
         * without the ->utc() a member two hours ahead has "14:00" landing in a
         * column everything else reads as UTC, and the message goes out two
         * hours late.
         */
        $channel->scheduledMessages()->create([
            'user_id' => $request->user()->id,
            'body' => $request->string('body')->trim()->value(),
            'send_at' => $request->date('send_at')?->utc(),
        ]);

        /*
         * The composer clears itself on success, so without a word back the
         * message looks lost rather than parked.
         *
         * Without the moment in it, deliberately. The browser sends an instant
         * and the server stores it in UTC, so naming the time here would name
         * it in the server's timezone — which is the very confusion this is
         * supposed to settle. The scheduled panel shows it in local time, and
         * that is where somebody checks it.
         */
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.message.scheduled'),
        ]);

        return back()->with('status', __('flashes.message.scheduled'));
    }

    /**
     * Change what a scheduled message says, or when it goes out.
     *
     * Only while it is still waiting: once it has been said there is a message
     * in the channel, and that one is edited through the ordinary message edit —
     * changing this row would rewrite history nobody saw change.
     */
    public function update(
        StoreScheduledMessageRequest $request,
        Workspace $workspace,
        Channel $channel,
        ScheduledMessage $scheduledMessage,
    ): RedirectResponse {
        $this->authorizeOwn($request, $workspace, $channel, $scheduledMessage);
        abort_unless($scheduledMessage->isPending(), 409, __('chat.already_sent'));

        $scheduledMessage->update([
            'body' => $request->string('body')->trim()->value(),
            'send_at' => $request->date('send_at')?->utc(),
        ]);

        return back()->with('status', __('flashes.message.updated'));
    }

    /**
     * Take it back before it is said.
     *
     * Deleted rather than marked withdrawn: nobody ever saw it, so there is no
     * history to keep. A sent one is a message in the channel, and taking that
     * back is what deleting a message is for.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ScheduledMessage $scheduledMessage,
    ): RedirectResponse {
        $this->authorizeOwn($request, $workspace, $channel, $scheduledMessage);
        abort_unless($scheduledMessage->isPending(), 409, __('chat.already_sent'));

        $scheduledMessage->delete();

        return back()->with('status', __('flashes.message.withdrawn'));
    }

    /**
     * Yours, in this channel, in this workspace.
     *
     * Somebody else's scheduled message is not theirs to touch — not even a
     * channel admin's: it has not been said yet, so there is nothing to
     * moderate, only somebody's draft to leave alone.
     */
    private function authorizeOwn(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ScheduledMessage $scheduledMessage,
    ): void {
        $this->channelIsReachable($workspace, $channel);
        abort_unless($scheduledMessage->channel_id === $channel->id, 404);
        abort_unless($scheduledMessage->user_id === $request->user()->id, 403);
    }
}
