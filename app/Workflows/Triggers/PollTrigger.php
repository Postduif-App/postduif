<?php

namespace App\Workflows\Triggers;

use App\Enums\WorkflowRecordType;
use App\Features\Polls;
use App\Models\Workspace;
use App\Workflows\RecordSnapshot;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * What the three poll triggers share.
 *
 * Three where the story asked for four: there is no poll-threshold-reached, and
 * that is not something left undone. A threshold is a vote plus a comparison,
 * and the comparison is what a condition is for — "als iemand stemt" met
 * "poll.top_votes is minstens 10" says exactly the same thing, in the two
 * halves the builder already has.
 *
 * Writing it as a trigger would have meant a number in the trigger settings,
 * which is a number in a place nobody looks when they wonder why a workflow
 * fired. The counts below are what make the condition possible: a condition can
 * compare a number but cannot produce one.
 */
abstract class PollTrigger extends WorkflowTrigger
{
    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel(
                'channel_id',
                __('workflows.triggers.poll.channel.label'),
                __('workflows.triggers.poll.channel.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    protected static function pollProvides(): array
    {
        return RecordSnapshot::paths(WorkflowRecordType::Poll);
    }

    public static function availableFor(Workspace $workspace): bool
    {
        return $workspace->hasFeature(Polls::class);
    }
}
