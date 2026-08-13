<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\DuplicateContract as CopyContract;
use App\Enums\WorkflowRecordType;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Start a fresh draft from a contract that has already been out.
 *
 * The same document, laid out the same way, for somebody new — and the reason
 * it is worth a step rather than a screen: a lease that goes out every month is
 * a workflow, and the copy is where that workflow starts.
 *
 * Not to be confused with sending from a template, which is what most workflows
 * want. A template is a mould, signed on our side and made to be copied a
 * hundred times; this is a real contract being reused, and everything that
 * happened to the original — the signers, their tokens, their signatures, the
 * signed copy — is deliberately left behind. See DuplicateContract, which
 * spells out why carrying any of it across would be claiming somebody signed
 * something they have never seen.
 *
 * What comes out is a draft with nobody on it. That is not an oversight: the
 * next step names the people, with add-contract-signer or with the screen.
 */
class DuplicateContract extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly CopyContract $copy) {}

    public static function label(): string
    {
        return __('workflows.actions.duplicate-contract.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.duplicate-contract.description');
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

            /*
             * Required, and the one field this step cannot do without. A
             * contract is named once and never renamed, so a copy that
             * inherited its title would sit in the list beside the original
             * with nothing to tell them apart — which is exactly what the
             * screen refuses too. A variable is the point: "Huurovereenkomst
             * {{ trigger.answers.naam }}".
             */
            WorkflowField::text(
                'title',
                __('workflows.actions.duplicate-contract.title.label'),
                __('workflows.actions.duplicate-contract.title.hint'),
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

        $original = $this->contract($context);

        $this->allowedTo($context, 'duplicate', $original);

        // A row whose PDF never arrived is the one thing the copy cannot be
        // made from — the same 404 the screen gives, said in a sentence that
        // ends up on the run screen instead.
        if (! $original->hasSource()) {
            throw new RuntimeException(__('workflows.errors.nothing_to_duplicate', [
                'title' => $original->title,
            ]));
        }

        $title = trim((string) $context->setting('title', ''));

        if ($title === '') {
            throw new RuntimeException(__('workflows.errors.no_contract_title'));
        }

        // The boxes come with it, and they are read off the original inside the
        // action. Loaded here because lazy loading is off in this application.
        $original->load('fields');

        $copy = $this->copy->handle($original, $this->actor($context), mb_substr($title, 0, 200));

        return $this->describe($copy);
    }
}
