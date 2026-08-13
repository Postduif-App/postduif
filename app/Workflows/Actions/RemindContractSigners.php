<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\RemindContractSigners as NudgeSigners;
use App\Enums\WorkflowRecordType;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;

/**
 * Nudge the people who have not answered a contract yet.
 *
 * The action the whole contract slice was written for: "verstuurd, en na drie
 * dagen nog niets" is the workflow every workspace that sends contracts ends up
 * wanting, and until now it was a job somebody had to remember.
 *
 * What it does not do is decide who gets one. That is the ordinary action's,
 * and it leaves out anybody who has already answered and anybody nudged in the
 * last day — so a workflow that fires more often than it should cannot turn
 * into harassment. It reports how many it actually sent, which is the honest
 * thing for a following step to read: nought is a perfectly ordinary answer.
 */
class RemindContractSigners extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly NudgeSigners $nudge) {}

    public static function label(): string
    {
        return __('workflows.actions.remind-contract-signers.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.remind-contract-signers.description');
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
            'reminded' => __('workflows.provides.contract.reminded'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $contract = $this->contract($context);

        /*
         * remind rather than view: a draft has nobody to remind and a withdrawn
         * one would be nudging people about something that no longer exists.
         * Asked now rather than when the workflow was written, because a
         * contract that was out then may be finished by the time this runs.
         */
        $this->allowedTo($context, 'remind', $contract);

        return [
            ...$this->describe($contract),
            'reminded' => $this->nudge->handle($contract),
        ];
    }
}
