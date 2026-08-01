<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Keeping a channel quiet, for one member.
 *
 * Not a channel setting: muting is a decision about your own attention, and
 * two people in the same busy channel will disagree about it. So it lives on
 * the membership, and nobody else can see or change it.
 */
class ChannelMuteController extends Controller
{
    /** How long a mute may run. Beyond a week, "until I switch it off" is the honest choice. */
    private const MAX_HOURS = 168;

    public function store(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $member = $this->member($request, $workspace, $channel);

        $validated = $request->validate([
            // Absent means no end: quiet until this member says otherwise.
            'hours' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_HOURS],
        ]);

        $until = isset($validated['hours'])
            ? now()->addHours($validated['hours'])
            : null;

        $channel->members()->updateExistingPivot($member, [
            'muted_at' => now(),
            'muted_until' => $until,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $until === null
                ? 'Meldingen voor dit kanaal staan uit.'
                : 'Meldingen voor dit kanaal staan uit tot '.$until->format('H:i').'.',
        ]);

        return back();
    }

    /**
     * Turn the sound back on.
     *
     * Both columns cleared, not just the end date: a row with muted_until in
     * the past and muted_at still set reads as "was muted once", which is not
     * something anything asks about — and leaving it invites a query that
     * forgets to check the second column.
     */
    public function destroy(Request $request, Workspace $workspace, Channel $channel): RedirectResponse
    {
        $member = $this->member($request, $workspace, $channel);

        $channel->members()->updateExistingPivot($member, [
            'muted_at' => null,
            'muted_until' => null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Meldingen voor dit kanaal staan weer aan.',
        ]);

        return back();
    }

    /**
     * You can only mute a channel you are in.
     *
     * Membership rather than a policy: there is nothing to authorise here
     * beyond being present — this touches one row that belongs to the person
     * asking, and it is invisible to everybody else.
     */
    private function member(Request $request, Workspace $workspace, Channel $channel): int
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $user = $request->user();

        abort_unless($channel->members()->whereKey($user->id)->exists(), 403);

        return $user->id;
    }
}
