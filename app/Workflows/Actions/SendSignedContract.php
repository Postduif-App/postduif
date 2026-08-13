<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\SendSignedContract as SendCopies;
use App\Enums\WorkflowRecordType;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;

/**
 * Mail everybody who signed their copy of the finished document.
 *
 * This happens by itself when a contract completes, so the reason to have it as
 * a step is the second time: an address that bounced, a copy somebody threw
 * away, a customer on the telephone asking for it again. "Opnieuw" is what
 * makes it send to people who already had one.
 *
 * Nought is an ordinary answer and not a failure. A contract whose signed copy
 * has not been composed — or could not be — has nothing to attach, and saying
 * so through the count is more use to a following step than an exception would
 * be. See Contract::signedCopyState.
 */
class SendSignedContract extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly SendCopies $sendCopies) {}

    public static function label(): string
    {
        return __('workflows.actions.send-signed-contract.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.send-signed-contract.description');
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
            WorkflowField::choice(
                'again',
                __('workflows.actions.send-signed-contract.again.label'),
                [
                    'no' => __('workflows.actions.send-signed-contract.again.no'),
                    'yes' => __('workflows.actions.send-signed-contract.again.yes'),
                ],
                __('workflows.actions.send-signed-contract.again.hint'),
                required: false,
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
            'sent' => __('workflows.provides.contract.copies_sent'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $contract = $this->contract($context);

        // download rather than view: this hands somebody the document itself,
        // which is the ability that question is about.
        $this->allowedTo($context, 'download', $contract);

        return [
            ...$this->describe($contract),
            'sent' => $this->sendCopies->handle($contract, $context->setting('again') === 'yes'),
        ];
    }
}
