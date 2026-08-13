<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\SaveContractSigners;
use App\Enums\ContractStatus;
use App\Enums\WorkflowRecordType;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put one more person on a contract that has not gone out yet.
 *
 * The step that lets a draft be built up out of what happens rather than out of
 * what somebody typed in advance: a form names the second tenant, a webhook
 * names the guarantor, and the contract collects them before anybody is asked
 * to sign anything.
 *
 * Only on a draft, and that is the whole of the difficulty. Once a contract is
 * out, its boxes point at the people who were on the list when it left — see
 * signer_index in SaveContractSigners — so a name appended afterwards is a
 * signature line nobody drew and a mail nobody expected. A workflow that wants
 * a signer added to something already sent is a workflow that should have
 * withdrawn it and started again.
 *
 * Appended, never replacing. SaveContractSigners takes the whole list and
 * matches on the address, so the existing rows keep their tokens: adding a
 * third name must not rotate the links of the two already there.
 */
class AddContractSigner extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly SaveContractSigners $saveSigners) {}

    public static function label(): string
    {
        return __('workflows.actions.add-contract-signer.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.add-contract-signer.description');
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

            // Both take variables, which is the only reason this is worth
            // having: the name and the address come out of whatever set the
            // workflow off.
            WorkflowField::text(
                'signer_name',
                __('workflows.actions.add-contract-signer.name.label'),
                __('workflows.actions.add-contract-signer.name.hint'),
            ),
            WorkflowField::text(
                'signer_email',
                __('workflows.actions.add-contract-signer.email.label'),
                __('workflows.actions.add-contract-signer.email.hint'),
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
            'signer.name' => __('workflows.provides.signer.name'),
            'signer.email' => __('workflows.provides.signer.email'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $contract = $this->contract($context);

        $this->allowedTo($context, 'update', $contract);

        if ($contract->status !== ContractStatus::Draft) {
            throw new RuntimeException(__('workflows.errors.contract_already_sent', [
                'title' => $contract->title,
            ]));
        }

        $recipient = $this->recipient($context, $contract);

        $this->saveSigners->handle($contract, [
            ...$this->existing($contract),
            $recipient,
        ]);

        return [
            ...$this->describe($contract),
            'signer' => $recipient,
        ];
    }

    /**
     * Everybody already on the list, in the order they are in.
     *
     * Passed back in whole because SaveContractSigners takes the roster rather
     * than an addition: what is not in the list it is given is removed, and a
     * step that handed it one name would empty the contract instead of
     * extending it.
     *
     * @return list<array{name: string, email: string, user_id: int|null}>
     */
    private function existing(Contract $contract): array
    {
        return $contract->signers()
            ->orderBy('signing_order')
            ->get()
            ->map(fn (ContractSigner $signer): array => [
                'name' => $signer->name,
                'email' => $signer->email,
                'user_id' => $signer->user_id,
            ])
            ->all();
    }

    /**
     * Who is being added, once the variables have been filled in.
     *
     * Checked here rather than when the step was saved, because until the run
     * there was nothing in the box but {{ trigger.answers.email }}. An address
     * that turns out to be empty or malformed stops the step with a sentence
     * somebody can read, rather than writing a signer nobody can reach.
     *
     * @return array{name: string, email: string}
     */
    private function recipient(WorkflowStepContext $context, Contract $contract): array
    {
        $name = trim((string) $context->setting('signer_name', ''));
        $email = trim((string) $context->setting('signer_email', ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(__('workflows.errors.bad_signer_email', ['email' => $email]));
        }

        if ($name === '') {
            throw new RuntimeException(__('workflows.errors.no_signer_name'));
        }

        /*
         * Somebody who is already on it stops the step rather than quietly
         * doing nothing. SaveContractSigners would match the address and rename
         * their row — which is a different thing from adding a signer, and not
         * what a step called "ondertekenaar toevoegen" should turn out to have
         * done.
         */
        $taken = $contract->signers()
            ->get()
            ->contains(fn (ContractSigner $signer): bool => mb_strtolower($signer->email) === mb_strtolower($email));

        if ($taken) {
            throw new RuntimeException(__('workflows.errors.signer_already_on', ['email' => $email]));
        }

        return ['name' => $name, 'email' => $email];
    }
}
