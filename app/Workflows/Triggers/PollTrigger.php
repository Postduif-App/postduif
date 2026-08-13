<?php

namespace App\Workflows\Triggers;

use App\Features\Polls;
use App\Models\Workflow;
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
        return [
            'poll.id' => __('workflows.provides.poll.id'),
            'poll.question' => __('workflows.provides.poll.question'),
            'poll.url' => __('workflows.provides.poll.url'),
            'poll.option_count' => __('workflows.provides.poll.option_count'),
            'poll.vote_count' => __('workflows.provides.poll.vote_count'),
            'poll.voter_count' => __('workflows.provides.poll.voter_count'),
            /*
             * The one in front and how many it has. Two paths rather than a
             * list, because what a workflow says out loud is nearly always
             * "X ligt voor met Y" — and because a condition can compare
             * top_votes and cannot compare a list.
             */
            'poll.leading_option' => __('workflows.provides.poll.leading_option'),
            'poll.top_votes' => __('workflows.provides.poll.top_votes'),
            'poll.is_closed' => __('workflows.provides.poll.is_closed'),
            'poll.closes_at' => __('workflows.provides.poll.closes_at'),
            'asker.id' => __('workflows.provides.poll.asker_id'),
            'asker.name' => __('workflows.provides.poll.asker_name'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
        ];
    }

    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(Polls::class) ?? false;
    }
}
