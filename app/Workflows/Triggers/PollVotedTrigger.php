<?php

namespace App\Workflows\Triggers;

/**
 * Somebody ticked an answer, or unticked one.
 *
 * The busiest of the three, and the one that earns the counts: with a condition
 * on poll.top_votes or poll.voter_count this is the threshold trigger the
 * builder does not otherwise have — "meld het in het kanaal zodra tien mensen
 * hetzelfde antwoord kozen" is one trigger and one rule.
 *
 * vote.ticked says which way it went. Unticking fires this too, because the
 * count changed and the count is what anybody watching a poll cares about.
 */
class PollVotedTrigger extends PollTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.poll-voted.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.poll-voted.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::pollProvides(),
            'vote.ticked' => __('workflows.provides.poll.vote_ticked'),
            'option.id' => __('workflows.provides.poll.option_id'),
            'option.label' => __('workflows.provides.poll.option_label'),
            'option.votes' => __('workflows.provides.poll.option_votes'),
            'voter.id' => __('workflows.provides.poll.voter_id'),
            'voter.name' => __('workflows.provides.poll.voter_name'),
        ];
    }
}
