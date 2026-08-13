<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Events\PollClosed;
use App\Events\PollCreated;
use App\Events\PollVoteCast;
use App\Models\Poll;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\RecordSnapshot;
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
            $context,
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
     * Worked out in RecordSnapshot, which the read-poll step reads from too:
     * the numbers move while a workflow is waiting, and a step that re-reads a
     * poll is only useful if it spells them the same way the trigger did.
     *
     * @return array<string, mixed>
     */
    private function context(Poll $poll): array
    {
        return RecordSnapshot::poll($poll);
    }
}
