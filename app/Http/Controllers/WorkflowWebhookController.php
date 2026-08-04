<?php

namespace App\Http\Controllers;

use App\Actions\Workflows\StartWorkflow;
use App\Features\Webhooks;
use App\Features\Workflows as WorkflowsFeature;
use App\Models\Workflow;
use App\Workflows\Triggers\WebhookTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowWebhookController extends Controller
{
    /**
     * Set a workflow off from outside.
     *
     * Stateless in the same way the channel webhooks are: no session, no CSRF,
     * no signed-in member. The token in the URL is the whole credential, which
     * is why it can be re-minted and why nothing here says whether a given
     * token was ever valid.
     */
    public function __invoke(Request $request, string $token, StartWorkflow $startWorkflow): JsonResponse
    {
        $workflow = Workflow::query()
            ->with('workspace')
            ->where('trigger_type', WebhookTrigger::key())
            ->where('webhook_token_hash', Workflow::hashWebhookToken($token))
            ->first();

        // 404 for unknown, for revoked, and for a workspace that has since said
        // no — one answer, so a caller holding a dead token learns nothing
        // about whether it was ever alive.
        abort_if($workflow === null, 404, __('workflows.webhook.unknown'));

        $workspace = $workflow->workspace;

        abort_unless(
            $workspace?->hasFeature(WorkflowsFeature::class)
                && $workspace->hasFeature(Webhooks::class),
            404,
            __('workflows.webhook.unknown'),
        );

        /*
         * Kept before anything else is decided, and kept even when the workflow
         * turns out to be switched off: a body that arrived while nothing was
         * listening is exactly the one somebody wants to look at when they come
         * to work out why.
         */
        $workflow->rememberWebhookPayload($request->all());

        $run = $startWorkflow->handle($workflow, ['payload' => $request->all()]);

        /*
         * 202 rather than 200, and it is not a formality: the run is on a queue
         * and nothing here waited for it. A sender that reads this as "done"
         * would be wrong, and the status code is the only place to say so.
         *
         * A switched-off workflow answers 202 as well. It accepted the call and
         * decided to do nothing, which from outside is indistinguishable from a
         * workflow whose first step was a condition that said no — and telling
         * those apart is not the sender's business.
         */
        return response()->json([
            'accepted' => true,
            'runId' => $run?->id,
        ], 202);
    }
}
