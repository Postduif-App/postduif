<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\CancelContract as StopContract;
use App\Actions\Contracts\SigningRefused;
use App\Enums\WorkflowRecordType;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Withdraw a contract that is still out.
 *
 * For the workflow that has decided the answer is not coming: a deadline
 * somebody set in a form, a customer who cancelled the order the contract was
 * for, a document that was superseded before anybody signed it.
 *
 * The links stay alive and are not meant to be killed — somebody following
 * theirs is told the contract was withdrawn rather than shown a 404, which is
 * the difference between an explanation and a telephone call. See
 * CancelContract, which spells that out.
 */
class CancelContract extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly StopContract $stop) {}

    public static function label(): string
    {
        return __('workflows.actions.cancel-contract.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.cancel-contract.description');
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

        $this->allowedTo($context, 'cancel', $contract);

        try {
            $contract = $this->stop->handle($contract);
        } catch (SigningRefused $refused) {
            /*
             * Nothing left to stop: it was signed, withdrawn or run out between
             * the workflow starting and this step getting its turn. Turned into
             * a failed step with the action's own sentence on it rather than
             * swallowed — a workflow whose whole point was to withdraw
             * something should not report success when it did not.
             */
            throw new RuntimeException($refused->getMessage(), previous: $refused);
        }

        return $this->describe($contract);
    }
}
