<?php

namespace App\Workflows\Actions;

use App\Actions\Contracts\PostContractToChannel as PostToChannel;
use App\Enums\WorkflowRecordType;
use App\Workflows\Actions\Concerns\ActsOnContracts;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put a contract in a channel, as the card people can act on.
 *
 * Not the same as sending a message with the link in it, which is why this
 * exists beside SendChannelMessage: what lands is the contract card the chat
 * draws for these — see PresentMessage — with the state on it and the buttons
 * that belong to whoever is looking.
 *
 * Posted in the name of the workflow's owner rather than as the bot, unlike an
 * ordinary message. The card is a way into a document with rights attached, and
 * "geplaatst door niemand" is not a state the conversation can show.
 */
class PostContractToChannel extends WorkflowAction
{
    use ActsOnContracts;

    public function __construct(private readonly PostToChannel $post) {}

    public static function label(): string
    {
        return __('workflows.actions.post-contract-to-channel.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.post-contract-to-channel.description');
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
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'contract.id' => __('workflows.provides.contract.id'),
            'contract.status' => __('workflows.provides.contract.status'),
            'contract.url' => __('workflows.provides.contract.url'),
            'message.id' => __('workflows.provides.message.id'),
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $this->guardContracts($context);

        $contract = $this->contract($context);
        $channel = $this->channel($context);
        $poster = $this->actor($context);

        $this->allowedTo($context, 'view', $contract);

        /*
         * And that they may say something there at all. The contract check
         * above is about the document; this is about the room, and a workflow
         * whose owner has since been taken out of a private channel must not go
         * on posting into it.
         */
        if ($poster->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_post', [
                'channel' => (string) $channel->name,
            ]));
        }

        $message = $this->post->handle($contract, $channel, $poster, $context->workflow);

        return [
            ...$this->describe($contract),
            'message' => ['id' => $message->id],
            'channel' => ['id' => $channel->id],
        ];
    }
}
