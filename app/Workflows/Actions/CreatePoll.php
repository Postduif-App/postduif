<?php

namespace App\Workflows\Actions;

use App\Actions\Polls\CreatePoll as AskChannel;
use App\Features\Polls;
use App\Models\Poll;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put a question to a channel.
 *
 * The recurring one, mostly: every Friday, "wie is er maandag op kantoor", with
 * a deadline that closes it before anybody has to chase. On the schedule
 * trigger that is a workflow of two blocks.
 *
 * The answers come out of a words field, which is the one field type that takes
 * a short list — the same control the keyword trigger uses. That has a real
 * consequence worth knowing: a words field takes no variables, so the answers
 * have to be written when the workflow is. A poll whose options come out of the
 * trigger is not something this can do, and pretending otherwise would mean a
 * comma-separated text box that silently produces one answer called
 * "ja, nee, misschien".
 */
class CreatePoll extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly AskChannel $ask) {}

    public static function label(): string
    {
        return __('workflows.actions.create-poll.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-poll.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::text(
                'question',
                __('workflows.actions.create-poll.question.label'),
                __('workflows.actions.create-poll.question.hint'),
            ),
            WorkflowField::words(
                'options',
                __('workflows.actions.create-poll.options.label'),
                __('workflows.actions.create-poll.options.hint'),
            ),
            WorkflowField::choice(
                'allows_multiple',
                __('workflows.actions.create-poll.multiple.label'),
                [
                    'no' => __('workflows.actions.create-poll.multiple.no'),
                    'yes' => __('workflows.actions.create-poll.multiple.yes'),
                ],
                required: false,
            ),
            WorkflowField::number(
                'closes_in_hours',
                __('workflows.actions.create-poll.closes.label'),
                __('workflows.actions.create-poll.closes.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'poll.id' => __('workflows.provides.poll.id'),
            'poll.question' => __('workflows.provides.poll.question'),
            'poll.url' => __('workflows.provides.poll.url'),
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Polls::class)) {
            throw new RuntimeException(__('workflows.errors.polls_off'));
        }

        $channel = $this->channel($context);
        $asker = $this->actor($context);

        /*
         * A poll is a message with a question in it, so the rule is the same as
         * for saying anything in this channel.
         */
        if ($asker->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_post', [
                'channel' => (string) $channel->name,
            ]));
        }

        $question = trim((string) $context->setting('question', ''));
        $options = $this->options($context);

        if ($question === '') {
            throw new RuntimeException(__('workflows.errors.empty_question'));
        }

        /*
         * Two, because a poll with one answer is not a question. Refused here
         * rather than left to the database: the words field cannot say "at
         * least two", and a poll with a single button is a thing somebody would
         * have to go and delete.
         */
        if (count($options) < 2) {
            throw new RuntimeException(__('workflows.errors.too_few_options'));
        }

        $poll = $this->ask->handle(
            channel: $channel,
            asker: $asker,
            question: $question,
            options: $options,
            allowsMultiple: $context->setting('allows_multiple') === 'yes',
            closesInHours: $this->closesInHours($context),
            workflow: $context->workflow,
        );

        return [
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'url' => route('chat.polls.show', [$channel->workspace, $poll]),
            ],
            'channel' => ['id' => $channel->id],
        ];
    }

    /**
     * The answers, trimmed and without the empties.
     *
     * @return list<string>
     */
    private function options(WorkflowStepContext $context): array
    {
        $options = array_map(
            fn (mixed $option): string => trim((string) $option),
            (array) $context->setting('options', []),
        );

        return array_values(array_filter($options, fn (string $option): bool => $option !== ''));
    }

    /**
     * How long the channel gets, bounded.
     *
     * A fortnight at the outside: a poll that stays open for a year is one
     * nobody will answer, and the number came out of a JSON column that an
     * older version of this action may have written.
     */
    private function closesInHours(WorkflowStepContext $context): ?int
    {
        $hours = $context->setting('closes_in_hours');

        if (blank($hours) || ! is_numeric($hours)) {
            return null;
        }

        return max(1, min(24 * 14, (int) $hours));
    }
}
