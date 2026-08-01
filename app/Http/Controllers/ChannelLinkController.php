<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChannelLinkRequest;
use App\Http\Requests\UpdateChannelLinkRequest;
use App\Models\Channel;
use App\Models\ChannelLink;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The buttons in the bar above a channel.
 *
 * Inertia redirects rather than the JSON the webhook manager next door uses,
 * and for the opposite reason: a webhook is fetched only when somebody opens
 * that panel, while these are drawn on every page load and already travel in
 * the chat payload. A redirect re-renders that payload, so the bar and the
 * management list can never disagree about what exists.
 */
class ChannelLinkController extends Controller
{
    public function store(
        StoreChannelLinkRequest $request,
        Workspace $workspace,
        Channel $channel,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $channel->links()->create([
            'label' => $request->string('label')->trim()->value(),
            'url' => $request->string('url')->trim()->value(),
            // Onto the end. Somebody adding a button has said nothing about
            // where it belongs, and the front of the bar is the one place it
            // certainly does not.
            'position' => ((int) $channel->links()->max('position')) + 1,
        ]);

        return back();
    }

    public function update(
        UpdateChannelLinkRequest $request,
        Workspace $workspace,
        Channel $channel,
        ChannelLink $link,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $link->update([
            ...$request->has('label') ? ['label' => $request->string('label')->trim()->value()] : [],
            ...$request->has('url') ? ['url' => $request->string('url')->trim()->value()] : [],
        ]);

        return back();
    }

    public function destroy(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ChannelLink $link,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('manageSettings', $channel);

        $link->delete();

        return back();
    }

    /**
     * Put the buttons in the given order.
     *
     * The whole list at once rather than a position per button: moving one
     * changes where the others sit, and saving those one request at a time
     * leaves the bar in an order nobody asked for whenever one of them fails.
     *
     * Ids that do not belong to this channel are dropped rather than refused —
     * they can only come from a list that has since changed, and the answer to
     * that is to order what is actually there.
     */
    public function reorder(
        Request $request,
        Workspace $workspace,
        Channel $channel,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('manageSettings', $channel);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ours = $channel->links()->pluck('id')->flip();

        DB::transaction(function () use ($channel, $validated, $ours): void {
            foreach (array_values($validated['ids']) as $position => $id) {
                if ($ours->has($id)) {
                    $channel->links()->whereKey($id)->update(['position' => $position]);
                }
            }
        });

        return back();
    }
}
