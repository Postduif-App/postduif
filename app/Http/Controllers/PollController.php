<?php

namespace App\Http\Controllers;

use App\Actions\Polls\CastVote;
use App\Actions\Polls\CreatePoll;
use App\Http\Requests\StorePollRequest;
use App\Models\Channel;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Questions put to a channel.
 *
 * A poll lands as an ordinary message holding a link, the way a transfer and a
 * secret request do — what makes it readable is the card PresentMessage draws.
 * There is no separate poll screen for that reason; the link is a fallback for
 * anybody who follows it out of context.
 */
class PollController extends Controller
{
    /**
     * Following the link out of context.
     *
     * A redirect into the channel rather than a page of its own, which is the
     * difference between this and a transfer or a secret request: everybody who
     * can see a poll is already in the channel it was asked in, so there is
     * nobody left to build a standalone screen for.
     *
     * The route still earns its keep — it is the stable shape PresentMessage
     * matches on to know a link is a poll, and it is where somebody lands who
     * pasted the URL somewhere else.
     */
    public function show(Workspace $workspace, Poll $poll): RedirectResponse
    {
        /*
         * The workspace is named here even though the redirect below could be
         * built without it, and leaving it out is a trap worth knowing about:
         * implicit route-model binding only resolves a parameter the controller
         * actually asks for, and EnsureFeatureIsActive reads the workspace off
         * the route. Drop it from this signature and the middleware gets a
         * string instead of a model and throws.
         */
        abort_unless($poll->workspace_id === $workspace->id, 404);

        $this->authorize('view', $poll->channel);

        return redirect()->route('chat.show', [
            $workspace->slug,
            $poll->channel_id,
        ]);
    }

    public function store(
        StorePollRequest $request,
        Workspace $workspace,
        Channel $channel,
        CreatePoll $createPoll,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $createPoll->handle(
            channel: $channel,
            asker: $request->user(),
            question: $request->string('question')->trim()->value(),
            options: $request->input('options', []),
            allowsMultiple: $request->boolean('allows_multiple'),
            closesInHours: $request->input('closes_in_hours') === null
                ? null
                : $request->integer('closes_in_hours'),
        );

        return back();
    }

    /**
     * Tick an answer, or take the tick off.
     *
     * One endpoint for both, because from where somebody is standing it is one
     * gesture: they click the answer. Which of the two it turns out to be is
     * the action's business — see CastVote.
     */
    public function vote(
        Workspace $workspace,
        Poll $poll,
        PollOption $option,
        CastVote $castVote,
        Request $request,
    ): RedirectResponse {
        abort_unless($poll->workspace_id === $workspace->id, 404);
        abort_unless($option->poll_id === $poll->id, 404);

        $this->authorize('vote', $poll);

        $castVote->handle($poll, $option, $request->user());

        return back();
    }

    /**
     * Stop it early.
     *
     * Recorded as closed_at rather than by moving closes_at, so the card can
     * tell "somebody stopped this" from "the moment passed" — two different
     * things to read in a channel.
     */
    public function close(Workspace $workspace, Poll $poll): RedirectResponse
    {
        abort_unless($poll->workspace_id === $workspace->id, 404);

        $this->authorize('close', $poll);

        if ($poll->closed_at === null) {
            $poll->forceFill(['closed_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Poll gesloten.']);

        return back();
    }

    /**
     * Take it off the latch again.
     *
     * Both ways of being shut are undone, not just the one somebody chose: a
     * poll whose moment has passed would close again the instant it reopened
     * if closes_at stayed where it was, so reopening drops the deadline and
     * leaves the question open until it is stopped by hand. A deadline still
     * ahead of us is left alone — that one has not shut anything yet.
     *
     * Votes already cast stay. Reopening is "this ran too short", not a reset,
     * and throwing away answers people gave in good faith is not something a
     * button in a channel should be able to do quietly.
     */
    public function reopen(Workspace $workspace, Poll $poll): RedirectResponse
    {
        abort_unless($poll->workspace_id === $workspace->id, 404);

        $this->authorize('reopen', $poll);

        $poll->forceFill([
            'closed_at' => null,
            'closes_at' => $poll->closes_at?->isPast() ? null : $poll->closes_at,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Poll heropend.']);

        return back();
    }
}
