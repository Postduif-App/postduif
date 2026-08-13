<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Features\Contracts;
use App\Features\WorkspaceFeature;

/**
 * Read a contract again.
 *
 * The case the whole story was written for. "Wacht drie dagen, en als er dan
 * nog steeds niemand getekend heeft, meld het" needs signed_count as it is
 * today; days_until_expiry is worked out against now() as well, so after a
 * Delay it counts down rather than standing still.
 */
class ReadContract extends ReadRecord
{
    public static function label(): string
    {
        return __('workflows.actions.read-contract.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.read-contract.description');
    }

    protected static function type(): WorkflowRecordType
    {
        return WorkflowRecordType::Contract;
    }

    /** @return class-string<WorkspaceFeature> */
    protected static function feature(): string
    {
        return Contracts::class;
    }

    protected static function fieldLabel(): string
    {
        return __('workflows.actions.fields.contract');
    }

    protected static function fieldHint(): string
    {
        return __('workflows.actions.fields.contract_hint');
    }
}
