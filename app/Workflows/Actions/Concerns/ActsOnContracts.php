<?php

namespace App\Workflows\Actions\Concerns;

use App\Features\Contracts;
use App\Models\Contract;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * The two questions every contract action has to ask before it does anything.
 *
 * The feature, because a workspace can switch contracts off long after somebody
 * wrote the step — and a run that carried on regardless would be mailing a
 * stranger a document on behalf of a workspace that has stopped doing that.
 * That check cannot live in the register: the register describes what exists,
 * not what this workspace is still doing.
 *
 * And the permission, asked of the workflow's owner rather than of whoever set
 * it off. A workflow runs with its owner's rights — see WorkflowStepContext —
 * so somebody who leaves, or loses their place in a workspace, takes what their
 * workflows could do with them.
 */
trait ActsOnContracts
{
    use FindsTargets;

    protected function guardContracts(WorkflowStepContext $context): void
    {
        if (! $context->workspace()->hasFeature(Contracts::class)) {
            throw new RuntimeException(__('workflows.errors.contracts_off'));
        }
    }

    /**
     * @param  string  $ability  A ContractPolicy method: view, cancel, remind, download, update.
     */
    protected function allowedTo(WorkflowStepContext $context, string $ability, Contract $contract): void
    {
        if ($this->actor($context)->cannot($ability, $contract)) {
            throw new RuntimeException(__('workflows.errors.may_not_touch_contract', [
                'title' => $contract->title,
            ]));
        }
    }

    /**
     * What every one of these hands the next step.
     *
     * Always the same three, whatever the action did, because what a following
     * step wants is nearly always the same: which contract, where it stands
     * now, and a link to put in a message.
     *
     * @return array<string, mixed>
     */
    protected function describe(Contract $contract): array
    {
        $contract = $contract->fresh() ?? $contract;

        // The model rather than its id: the route is keyed on the workspace's
        // slug, and handing it a number produces a link to nowhere.
        $contract->loadMissing('workspace');

        return [
            'contract' => [
                'id' => $contract->id,
                'title' => $contract->title,
                'status' => $contract->status->value,
                'url' => route('chat.contracts.show', [$contract->workspace, $contract]),
            ],
        ];
    }
}
