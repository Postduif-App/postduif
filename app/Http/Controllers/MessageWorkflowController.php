<?php

namespace App\Http\Controllers;

use App\Actions\Workflows\StartWorkflow;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\LinkTrigger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MessageWorkflowController extends Controller
{
    /**
     * Set a workflow off by hand, from a message.
     *
     * The one trigger an ordinary member operates. Who may *write* workflows is
     * a beheerder's question and stays that way; who may set one going is a
     * different one, and answering it the same way would leave this trigger
     * with nobody to press it.
     *
     * What keeps that from being a hole is what the run does afterwards: the
     * steps use the owner's rights, not the starter's, so pressing this cannot
     * reach anywhere the beheerder who wrote it could not already reach.
     */
    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        Workflow $workflow,
        StartWorkflow $startWorkflow,
    ): RedirectResponse {
        /*
         * Everything in the path checked against everything above it, by hand
         * because the route cannot scope its bindings — see routes/chat.php.
         * Three lines instead of one word, and worth being explicit about: a
         * missing one here is a message from another channel being handed to a
         * workflow in another workspace.
         */
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($message->channel_id === $channel->id, 404);
        abort_unless($workflow->workspace_id === $workspace->id, 404);

        // Being able to see the message is the whole of it: a workflow started
        // from a message you may read is a workflow started on something you
        // were already looking at.
        $this->authorize('view', $channel);

        /*
         * One 404 for three refusals — the wrong kind of trigger, switched off,
         * and the feature closed. A workflow that is not on offer should be
         * indistinguishable from one that does not exist, or the menu becomes a
         * way to enumerate what a workspace has.
         */
        abort_unless(
            $workflow->trigger_type === LinkTrigger::key()
                && $workflow->isEnabled()
                && $workspace->hasFeature(WorkflowsFeature::class),
            404,
        );

        $started = $startWorkflow->handle($workflow, [
            'message' => ['id' => $message->id, 'text' => $message->body],
            'channel' => ['id' => $channel->id, 'name' => $channel->name],

            // The one who pressed it, and separately the one who wrote the
            // message they pressed it on — the same pair the emoji trigger
            // keeps apart, for the same reason.
            'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'author' => ['id' => $message->user_id, 'name' => $message->author?->name],
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
