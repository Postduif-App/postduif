<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Whether this channel pushes right away for this member, or waits for the
 * away summary like the rest.
 *
 * A membership setting, on the same row as the mute — see ChannelMuteController
 * for why: it is a decision about one member's own attention, and two people
 * in the same channel may disagree about it.
 */
class ChannelInstantNotificationController extends Controller
{
    public function update(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $member = $this->member($request, $workspace, $channel);

        $validated = $request->validate([
            // Absent means "follow my account default" — the third answer a
            // plain boolean cannot give. The 'boolean' rule accepts an
            // explicit null through 'nullable' without coercing it to false.
            'instant' => ['nullable', 'boolean'],
        ]);

        $channel->members()->updateExistingPivot($member, [
            'instant_notifications' => $validated['instant'] ?? null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.channel.notifications_updated'),
        ]);

        return back();
    }

    /**
     * You can only change this for a channel you are in.
     *
     * Membership rather than a policy: there is nothing to authorise here
     * beyond being present — this touches one row that belongs to the person
     * asking, and it is invisible to everybody else.
     */
    private function member(Request $request, Workspace $workspace, Channel $channel): int
    {
        $this->channelIsReachable($workspace, $channel);

        $user = $request->user();

        abort_unless($channel->members()->whereKey($user->id)->exists(), 403);

        return $user->id;
    }
}
