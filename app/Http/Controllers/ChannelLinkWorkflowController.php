<?php

namespace App\Http\Controllers;

use App\Actions\Workflows\StartWorkflow;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\ChannelLink;
use App\Models\Workspace;
use App\Workflows\Triggers\ButtonTrigger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChannelLinkWorkflowController extends Controller
{
    /**
     * Press a button in the bar above a channel.
     *
     * The same shape as starting one from a message — see
     * MessageWorkflowController, which explains why an ordinary member may set
     * a workflow going at all — with one difference: the workflow is not named
     * in the request. It is whatever the button was set up to start, so nobody
     * pressing this gets to choose, and there is nothing here to enumerate a
     * workspace's workflows with.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ChannelLink $link,
        StartWorkflow $startWorkflow,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        // Seeing the channel is the whole of it: the button is drawn for
        // everyone who can, guests included, and a button somebody can see but
        // not press is a button that reads as broken.
        $this->authorize('view', $channel);

        $workflow = $link->workflow;

        /*
         * One 404 for four refusals — not a workflow button at all, the wrong
         * kind of trigger, switched off, and the feature closed. Same reasoning
         * as the message menu: what is not on offer should be indistinguishable
         * from what does not exist.
         */
        abort_unless(
            $workflow !== null
                && $workflow->workspace_id === $workspace->id
                && $workflow->trigger_type === ButtonTrigger::key()
                && $workflow->isEnabled()
                && $workspace->hasFeature(WorkflowsFeature::class),
            404,
        );

        $started = $startWorkflow->handle($workflow, [
            'channel' => ['id' => $channel->id, 'name' => $channel->name],
            // No author beside it, unlike the message menu: nothing was pointed
            // at, so the only person in this run is the one who pressed it.
            'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
        ]);

        Inertia::flash('toast', [
            'type' => $started === null ? 'error' : 'success',
            'message' => $started === null
                ? __('workflows.link.refused')
                : __('workflows.link.started', ['name' => $workflow->name]),
        ]);

        return back();
    }
}
