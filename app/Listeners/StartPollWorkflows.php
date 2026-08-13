<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\PollClosed;
use App\Events\PollCreated;
use App\Events\PollVoteCast;
use App\Models\Poll;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\Triggers\PollClosedTrigger;
use App\Workflows\Triggers\PollCreatedTrigger;
use App\Workflows\Triggers\PollVotedTrigger;
use App\Workflows\WorkflowTrigger;

/**
 * Set off the workflows that were waiting on a poll.
 *
 * The counting is the whole of this class. A poll's own screen works out its
 * tally when somebody looks at it; a workflow cannot, so the numbers are worked
 * out here and handed over — which is what turns "als iemand stemt" plus a
 * condition into the threshold trigger the builder does not have.
 *
 * One query for the options with their vote counts, not one per option: a poll
 * with eight answers being voted on by a channel of forty is not the place to
 * discover an N+1.
 */
class StartPollWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleCreated(PollCreated $event): void
    {
        $this->start($event->pollId, PollCreatedTrigger::class);
    }

    public function handleClosed(PollClosed $event): void
    {
        $this->start($event->pollId, PollClosedTrigger::class);
    }

    public function handleVoted(PollVoteCast $event): void
    {
        $this->start($event->pollId, PollVotedTrigger::class, function (Poll $poll) use ($event): array {
            $option = $poll->options->firstWhere('id', $event->optionId);
            $voter = User::find($event->voterId);

            return [
                'vote' => ['ticked' => $event->ticked],
                'option' => [
                    'id' => $option?->id,
                    'label' => $option?->label,
                    // Nought when the option has gone, which a vote taken off a
                    // deleted answer can just about produce.
                    'votes' => $option === null ? 0 : $option->votes_count,
                ],
                'voter' => ['id' => $voter?->id, 'name' => $voter?->name],
            ];
        });
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     * @param  (callable(Poll): array<string, mixed>)|null  $extra  What this particular happening adds.
     */
    private function start(string $pollId, string $trigger, ?callable $extra = null): void
    {
        $poll = Poll::query()
            ->with(['channel.workspace', 'asker', 'options' => fn ($query) => $query->withCount('votes')])
            ->find($pollId);

        if ($poll === null) {
            return;
        }

        $context = [
            ...$this->context($poll),
            ...$extra === null ? [] : $extra($poll),
        ];

        $this->startWorkflows->handle(
            $poll->channel?->workspace,
            $trigger,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $poll) ? $context : null,
        );
    }

    private function matches(Workflow $workflow, Poll $poll): bool
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // Loosely, because the id came out of a JSON column where 7 may be "7".
        return blank($channelId)
            || ! ctype_digit((string) $channelId)
            || (int) $channelId === $poll->channel_id;
    }

    /**
     * The tally, as PollTrigger::pollProvides describes it.
     *
     * leading_option is the one in front and nothing about ties: two answers on
     * four votes each will name one of them, and which one is the order the
     * options were written in. Saying "gelijkspel" would be a fourth path
     * nobody asked for; a workflow that cares can compare top_votes against
     * vote_count itself.
     *
     * @return array<string, mixed>
     */
    private function context(Poll $poll): array
    {
        $leader = $poll->options->sortByDesc('votes_count')->first();

        return [
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'url' => route('chat.polls.show', [$poll->workspace, $poll]),
                'option_count' => $poll->options->count(),
                'vote_count' => $poll->options->sum('votes_count'),
                // How many people answered, which on a multiple-choice poll is
                // not how many votes were cast — see Poll::voterCount.
                'voter_count' => $poll->voterCount(),
                'leading_option' => $leader?->label,
                'top_votes' => $leader === null ? 0 : $leader->votes_count,
                'is_closed' => $poll->isClosed(),
                'closes_at' => $poll->closes_at?->toIso8601String(),
            ],
            'asker' => ['id' => $poll->created_by, 'name' => $poll->asker?->name],
            'channel' => ['id' => $poll->channel_id, 'name' => $poll->channel?->name],
        ];
    }
}
