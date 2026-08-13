<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\InstantiateTemplate;
use App\Actions\Contracts\SaveContractSigners;
use App\Actions\Contracts\SendContract;
use App\Enums\WorkflowRecordType;
use App\Models\Contract;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Send a contract to somebody, out of a template that was prepared for it.
 *
 * The most useful thing in this whole slice, and the one that turns the builder
 * into something a workspace runs on: a form comes in, and the contract goes
 * out to the address that was typed into it, signed on our side already,
 * without anybody opening a screen.
 *
 * From a template rather than from a contract, and that is not a limitation but
 * the point. A template is a document somebody has read, laid out and put their
 * own signature under precisely so that the hundred copies made from it do not
 * each need that attention — see InstantiateTemplate. A workflow that could
 * send an arbitrary contract would be a workflow that could mail a stranger a
 * document nobody had checked.
 *
 * One recipient. The API takes several because a machine that knows about a
 * two-party lease can name both; a workflow knows about one address it read out
 * of a trigger, and inventing a second set of fields for the templates that
 * want two would put the complication in front of everybody who does not. A
 * template expecting more than one says so plainly instead.
 */
class SendContractFromTemplate extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(
        private readonly InstantiateTemplate $instantiate,
        private readonly SaveContractSigners $saveSigners,
        private readonly SendContract $send,
    ) {}

    public static function label(): string
    {
        return __('workflows.actions.send-contract-from-template.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.send-contract-from-template.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'template_id',
                WorkflowRecordType::ContractTemplate,
                __('workflows.actions.send-contract-from-template.template.label'),
                __('workflows.actions.send-contract-from-template.template.hint'),
                required: true,
            ),

            /*
             * Both take variables, which is the whole reason this action is
             * worth having: the name and the address come out of whatever set
             * the workflow off — a form, a webhook, a message.
             */
            WorkflowField::text(
                'signer_name',
                __('workflows.actions.send-contract-from-template.name.label'),
                __('workflows.actions.send-contract-from-template.name.hint'),
            ),
            WorkflowField::text(
                'signer_email',
                __('workflows.actions.send-contract-from-template.email.label'),
                __('workflows.actions.send-contract-from-template.email.hint'),
            ),

            /*
             * Optional, and the template's own title when it is left alone —
             * which is right for a lease sent by a machine, and wrong for a
             * workspace that wants the customer's name in it. Both are one box
             * away.
             */
            WorkflowField::text(
                'title',
                __('workflows.actions.send-contract-from-template.title.label'),
                __('workflows.actions.send-contract-from-template.title.hint'),
                required: false,
            ),
            WorkflowField::number(
                'valid_for_days',
                __('workflows.actions.send-contract-from-template.days.label'),
                __('workflows.actions.send-contract-from-template.days.hint'),
                required: false,
            ),
            WorkflowField::channel(
                'channel_id',
                __('workflows.actions.send-contract-from-template.channel.label'),
                __('workflows.actions.send-contract-from-template.channel.hint'),
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
            'signer.name' => __('workflows.provides.signer.name'),
            'signer.email' => __('workflows.provides.signer.email'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $template = $this->contractTemplate($context);
        $author = $this->actor($context);

        if ($author->cannot('create', [Contract::class, $context->workspace()])) {
            throw new RuntimeException(__('workflows.errors.may_not_send_contract'));
        }

        /*
         * Asked here rather than left to InstantiateTemplate, which throws in
         * English at a developer. This one ends up on a run screen in front of
         * whoever wrote the workflow, and "dit sjabloon is nog niet af" is
         * something they can go and fix.
         */
        if (! $template->isReadyToSend()) {
            throw new RuntimeException(__('workflows.errors.template_unfinished', ['title' => $template->title]));
        }

        if ($template->required_signers !== 1) {
            throw new RuntimeException(__('workflows.errors.template_wants_more_signers', [
                'title' => $template->title,
                'count' => $template->required_signers,
            ]));
        }

        $recipient = $this->recipient($context, $template);

        /*
         * Copying the document and writing down who it is for is one
         * transaction; sending is outside it. A mail is the one side effect
         * with no rollback — the same line SendContract itself draws, and the
         * reason it draws it: a link to a contract that never got committed is
         * worse than no mail at all.
         */
        [$contract, $roster] = DB::transaction(function () use ($template, $author, $context, $recipient): array {
            $instance = $this->instantiate->handle($template, $author, $this->title($context));

            $roster = $this->instantiate->roster($instance->contract, [$recipient]);

            $this->saveSigners->handle($instance->contract, $roster);

            return [$instance->contract, $roster];
        });

        $contract = $this->send->handle(
            contract: $contract->fresh(['signers']),
            signers: $roster,
            validForDays: $this->validForDays($context),
            notifyChannelId: $this->notifyChannel($context),
        );

        return [
            ...$this->describe($contract),
            'signer' => $recipient,
        ];
    }

    /**
     * Who it goes to, once the variables have been filled in.
     *
     * Both are checked after resolution rather than when the step was saved,
     * because until the run there was nothing there but "{{ trigger.answers.email }}".
     * An address that turns out to be empty or malformed stops the run here,
     * where it is one failed step with a readable reason — rather than after
     * the document has been copied and a row written for nobody.
     *
     * @return array{name: string, email: string}
     */
    private function recipient(WorkflowStepContext $context, Contract $template): array
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
         * And never the person who signed the template. Their row is already on
         * the copy, signed, and a recipient with the same address would be
         * matched to it by SaveContractSigners — inheriting a signature they
         * never made on this document. The same refusal the API makes, for the
         * same reason.
         */
        $signer = $template->templateSigner();

        if ($signer !== null && mb_strtolower($signer->email) === mb_strtolower($email)) {
            throw new RuntimeException(__('workflows.errors.signer_is_sender', ['email' => $email]));
        }

        return ['name' => $name, 'email' => $email];
    }

    private function title(WorkflowStepContext $context): ?string
    {
        $title = trim((string) $context->setting('title', ''));

        return $title === '' ? null : mb_substr($title, 0, 200);
    }

    /**
     * How long they get, or whatever the template already said.
     *
     * Bounded here as well as in the builder because the value may have arrived
     * from a JSON column an older version of this action wrote: a deadline of
     * nought days is a contract that expires before the mail lands.
     */
    private function validForDays(WorkflowStepContext $context): ?int
    {
        $days = $context->setting('valid_for_days');

        if (blank($days) || ! is_numeric($days)) {
            return null;
        }

        return max(1, min(365, (int) $days));
    }

    private function notifyChannel(WorkflowStepContext $context): ?int
    {
        return blank($context->setting('channel_id'))
            ? null
            : $this->channel($context)->id;
    }
}
