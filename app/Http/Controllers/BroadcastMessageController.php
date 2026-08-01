<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BroadcastToChannels;
use App\Http\Requests\BroadcastMessageRequest;
use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

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

        $sent = $broadcastToChannels->handle($user, $channels, $request->string('body')->trim()->value());

        if ($sent === []) {
            return back()->withErrors([
                'channels' => 'In geen van die kanalen mag je posten.',
            ]);
        }

        $first = $sent[0];

        // Onto the first channel it landed in, so the sender sees it arrive
        // rather than being told it did.
        return redirect()
            ->route('chat.show', [$workspace, $first])
            ->with('status', count($sent) === 1
                ? 'Bericht geplaatst in 1 kanaal.'
                : 'Bericht geplaatst in '.count($sent).' kanalen.');
    }
}
