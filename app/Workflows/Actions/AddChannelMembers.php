<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\AddChannelMembers as AddChannelMembersAction;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put somebody in a channel.
 *
 * One person per step rather than a list, which is a choice about the builder
 * more than about the action: a row of three steps reads as three things
 * happening, and each one can carry its own condition. A multi-select would
 * make "add the person who triggered this, and also the duty manager" one
 * field with two very different kinds of value in it.
 *
 * Goes through the ordinary AddChannelMembers, which already refuses anybody
 * who is not in the workspace and quietly skips those already in the channel.
 */
class AddChannelMembers extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly AddChannelMembersAction $addMembers) {}

    public static function label(): string
    {
        return __('workflows.actions.add-channel-members.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.add-channel-members.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::member('user_id', __('workflows.actions.fields.person')),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return ['added' => __('workflows.provides.added')];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $channel = $this->channel($context);
        $member = $this->member($context);

        if ($this->actor($context)->cannot('addMembers', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_add_members'));
        }

        $added = $this->addMembers->handle($channel, [$member->id]);

        /*
         * Says whether anything actually changed, so a following step can hold
         * its welcome message back for somebody who was already in the channel.
         */
        return ['added' => $added->isNotEmpty(), 'channel' => ['id' => $channel->id]];
    }
}
