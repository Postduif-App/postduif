<?php

namespace App\Workflows\Actions;

use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;

/**
 * Look a channel up and remember what it says.
 *
 * The only action here that changes nothing. It exists so that a later step can
 * write "#{{ steps.0.channel.name }} heeft nu {{ steps.0.channel.members }}
 * leden" without the workflow having to carry those facts in its own text —
 * which would make them wrong the moment anything changed.
 */
class GetChannelInfo extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.get-channel-info.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.get-channel-info.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [WorkflowField::channel('channel_id', __('workflows.actions.fields.channel'))];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
            'channel.topic' => __('workflows.provides.channel.topic'),
            'channel.members' => __('workflows.provides.channel.members'),
            'channel.archived' => __('workflows.provides.channel.archived'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $channel = $this->channel($context);

        return [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'topic' => $channel->topic,
                'members' => $channel->members()->count(),
                'archived' => $channel->archived_at !== null,
            ],
        ];
    }
}
