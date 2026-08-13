<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Jobs\RenderSignedContractJob;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Have another go at composing the signed copy.
 *
 * The other half of the render-failed trigger: a workflow that tells the
 * beheerder something went wrong can put the retry in the same breath, so the
 * ordinary case — a machine that was busy, a step that timed out — fixes itself
 * before anybody reads the message.
 *
 * Only for a contract that is finished. Anything else has nothing to render:
 * the document is composed out of the signatures, and there are none yet.
 */
class RetryContractRender extends WorkflowAction
{
    use ActsOnContracts;

    public static function label(): string
    {
        return __('workflows.actions.retry-contract-render.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.retry-contract-render.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'contract_id',
                WorkflowRecordType::Contract,
                __('workflows.actions.fields.contract'),
                __('workflows.actions.fields.contract_hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'contract.id' => __('workflows.provides.contract.id'),
            'contract.status' => __('workflows.provides.contract.status'),
            'contract.url' => __('workflows.provides.contract.url'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $contract = $this->contract($context);

        /*
         * download rather than update, which is the same ability the button on
         * the overview asks for — and it has to be: update() refuses a contract
         * anybody has signed, which is every contract this action is for.
         */
        $this->allowedTo($context, 'download', $contract);

        if (! $contract->status->isEvidence()) {
            throw new RuntimeException(__('workflows.errors.nothing_to_render', ['title' => $contract->title]));
        }

        /*
         * The mark comes off first, as it does on the screen: it is what makes
         * the overview offer "opnieuw proberen" beside a finished contract, and
         * leaving it standing while a fresh attempt is in flight would keep
         * telling everybody the copy had failed.
         */
        if ($contract->render_failed_at !== null) {
            $contract->forceFill(['render_failed_at' => null])->save();
        }

        /*
         * The job is unique per contract — see its uniqueId — so a workflow
         * that fires twice, or a beheerder pressing the button while this runs,
         * cannot put two renders of the same document in flight racing to write
         * the same file.
         */
        RenderSignedContractJob::dispatch($contract->id);

        return $this->describe($contract);
    }
}
