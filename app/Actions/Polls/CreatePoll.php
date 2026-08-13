<?php

namespace App\Actions\Polls;

use App\Actions\Chat\SendMessage;
use App\Events\PollCreated;
use App\Models\Channel;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePoll
{
    public function __construct(private SendMessage $sendMessage) {}

    /**
     * Put a question to a channel.
     *
     * The poll and its answers are one thing — a poll with no answers is not a
     * question — so they go in together or not at all.
     *
     * The message follows after the transaction commits, as it does for a
     * transfer and a secret request: a message is not something a rollback can
     * take back.
     *
     * @param  array<int, string>  $options  At least two, in order.
     * @param  int|null  $closesInHours  Counted from now, or null for a poll
     *                                   that stays open until somebody closes it. Hours rather than a
     *                                   moment, as CreateTransfer takes days: what the asker is deciding
     *                                   is how long the channel gets, not a date on a calendar.
     */
    public function handle(
        Channel $channel,
        User $asker,
        string $question,
        array $options,
        bool $allowsMultiple = false,
        ?int $closesInHours = null,
    ): Poll {
        $poll = DB::transaction(function () use (
            $channel,
            $asker,
            $question,
            $options,
            $allowsMultiple,
            $closesInHours,
        ): Poll {
            $poll = Poll::create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'created_by' => $asker->id,
                'question' => $question,
                'allows_multiple' => $allowsMultiple,
                'closes_at' => $closesInHours === null ? null : now()->addHours($closesInHours),
            ]);

            // Deduplicated rather than left to the unique index: somebody
            // pasting a list twice is not an error worth an exception.
            foreach (array_values(array_unique($options)) as $position => $label) {
                PollOption::create([
                    'poll_id' => $poll->id,
                    'label' => $label,
                    'position' => $position,
                ]);
            }

            return $poll;
        });

        $this->sendMessage->handle(
            channel: $channel,
            author: $asker,
            body: trim($question.' '.route('chat.polls.show', [$channel->workspace->slug, $poll->id])),
        );

        /*
         * After the message rather than before, so anything acting on this
         * finds a poll that is complete and already visible in the channel.
         */
        PollCreated::dispatch($poll->id);

        return $poll;
    }
}
