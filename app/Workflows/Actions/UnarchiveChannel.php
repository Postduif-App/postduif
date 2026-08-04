<?php

namespace App\Workflows\Actions;

use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Open a closed channel again.
 *
 * A separate action rather than a second meaning for archiving, for the reason
 * the pin pair gives: a workflow runs many times, so a step that toggled would
 * mean the opposite of itself every other run.
 */
class UnarchiveChannel extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.unarchive-channel.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.unarchive-channel.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [WorkflowField::channel('channel_id', __('workflows.actions.fields.channel'))];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $channel = $this->channel($context);

        if ($this->actor($context)->cannot('archiveChannel', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_archive'));
        }

        if ($channel->archived_at !== null) {
            $channel->forceFill(['archived_at' => null])->save();
        }

        return ['channel' => ['id' => $channel->id, 'name' => $channel->name]];
    }
}
