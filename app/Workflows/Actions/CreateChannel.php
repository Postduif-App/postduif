<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\CreateChannel as CreateChannelAction;
use App\Enums\ChannelType;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Open a new channel.
 *
 * The name accepts variables, which is most of the point: a workflow that makes
 * a channel per customer or per week is the case this exists for. It is slugged
 * on the way in by the ordinary CreateChannel, so "Klant Jansen — week 32"
 * arrives as a channel name rather than as a refusal.
 *
 * Hands the new channel's id back, so the steps after it can put people in it
 * and say something there without anybody having to name it twice.
 */
class CreateChannel extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly CreateChannelAction $createChannel) {}

    public static function label(): string
    {
        return __('workflows.actions.create-channel.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-channel.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text('name', __('workflows.actions.fields.channel_name'), __('workflows.actions.fields.channel_name_hint')),
            WorkflowField::choice('type', __('workflows.actions.fields.channel_type'), [
                ChannelType::Public->value => __('workflows.actions.create-channel.public'),
                ChannelType::Private->value => __('workflows.actions.create-channel.private'),
            ], required: false),
            WorkflowField::text('topic', __('workflows.actions.fields.topic'), null, required: false),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $owner = $this->actor($context);
        $workspace = $context->workspace();
        $name = trim((string) $context->setting('name', ''));

        if ($name === '') {
            throw new RuntimeException(__('workflows.errors.no_channel_name'));
        }

        /*
         * The workspace may only let admins open channels, and the owner may
         * have been demoted since. Asked at the moment of making rather than at
         * the moment of writing, for the same reason every other check here is.
         */
        if ($owner->cannot('createChannel', $workspace)) {
            throw new RuntimeException(__('workflows.errors.may_not_create_channel'));
        }

        /*
         * Public unless somebody said otherwise. The safer default would be
         * private, but it is the wrong one here: a workflow that quietly makes
         * channels nobody can find is how a workspace ends up with a hundred of
         * them. Something visible gets noticed and cleaned up.
         */
        $type = ChannelType::tryFrom((string) $context->setting('type', '')) ?? ChannelType::Public;

        $channel = $this->createChannel->handle(
            workspace: $workspace,
            creator: $owner,
            name: $name,
            type: $type,
            topic: blank($context->setting('topic')) ? null : (string) $context->setting('topic'),
        );

        return ['channel' => ['id' => $channel->id, 'name' => $channel->name]];
    }
}
