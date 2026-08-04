<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BroadcastToChannels;
use App\Http\Requests\BroadcastMessageRequest;
use App\Models\Channel;
use App\Models\ScheduledBroadcast;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One message, several channels.
 *
 * The selection travels as channel ids and tag names together: a tag is a
 * shorthand for a set of channels, and resolving it here rather than in the
 * browser means it means the same thing at the moment of sending as it did when
 * the dialog was opened.
 */
class BroadcastMessageController extends Controller
{
    public function store(
        BroadcastMessageRequest $request,
        Workspace $workspace,
        BroadcastToChannels $broadcastToChannels,
    ): RedirectResponse {
        $user = $request->user();
        $ids = $request->array('channels');
        $tags = $request->array('tags');

        $channels = $workspace->channels()
            // Scoped to what this member can see before anything else: a tag
            // must not become a way to reach a channel they do not know exists.
            ->visibleTo($user)
            ->whereNull('archived_at')
            ->where(fn (Builder $chosen) => $chosen
                ->when($ids !== [], fn (Builder $query) => $query->whereIn('id', $ids))
                ->when(
                    $tags !== [],
                    fn (Builder $query) => $query->orWhereHas(
                        'tags',
                        fn (Builder $tagged) => $tagged->whereIn('name', $tags)
                    )
                ))
            ->get();

        $body = $request->string('body')->trim()->value();

        /*
         * Scheduled rather than sent, with the channels as they were chosen.
         * Deliberately after the visibility filter above and before the posting
         * check: which channels were meant is settled now, whether this member
         * may write in them is asked when it goes out — see
         * DispatchScheduledBroadcasts.
         */
        if ($request->filled('send_at')) {
            $broadcast = ScheduledBroadcast::create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'body' => $body,
                'send_at' => $request->date('send_at'),
            ]);

            $broadcast->channels()->sync($channels->pluck('id'));

            return back()->with('status', trans_choice(
                'chat.broadcast_scheduled',
                $channels->count(),
            ));
        }

        $sent = $broadcastToChannels->handle($user, $channels, $body);

        if ($sent === []) {
            return back()->withErrors([
                'channels' => __('chat.broadcast_none_allowed'),
            ]);
        }

        $first = $sent[0];

        // Onto the first channel it landed in, so the sender sees it arrive
        // rather than being told it did.
        return redirect()
            ->route('chat.show', [$workspace, $first])
            ->with('status', trans_choice('chat.broadcast_posted', count($sent)));
    }

    /**
     * Stop one before it goes out.
     *
     * Only your own, and only while it is still pending: an announcement that
     * has already landed in six channels cannot be taken back, and pretending
     * otherwise would be worse than saying so.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        ScheduledBroadcast $scheduledBroadcast,
    ): RedirectResponse {
        abort_unless(
            $scheduledBroadcast->workspace_id === $workspace->id
                && $scheduledBroadcast->created_by === $request->user()->id,
            404,
        );

        abort_unless($scheduledBroadcast->isPending(), 409, __('chat.already_sent'));

        $scheduledBroadcast->delete();

        return back()->with('status', __('chat.broadcast_withdrawn'));
    }
}
