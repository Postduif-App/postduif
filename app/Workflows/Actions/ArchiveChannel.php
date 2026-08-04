<?php

namespace App\Workflows\Actions;

use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Close a channel without throwing anything away.
 *
 * The action that most explains why writing workflows is a beheerder's job:
 * anybody who can put this step in a workflow can close any channel in the
 * workspace, and it runs with their rights rather than with the rights of
 * whoever happened to set the trigger off.
 *
 * archived_at is written straight to the row because it is deliberately not
 * fillable — it is never set from a form field, here or in the channel screen.
 */
class ArchiveChannel extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.archive-channel.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.archive-channel.description');
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

        // Already closed is not a failure. A rule that says "archive this" has
        // got what it asked for, and a run that broke over it would be noise.
        if ($channel->archived_at === null) {
            $channel->forceFill(['archived_at' => now()])->save();
        }

        return ['channel' => ['id' => $channel->id, 'name' => $channel->name]];
    }
}
