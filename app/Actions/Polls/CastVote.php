<?php

namespace App\Actions\Polls;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CastVote
{
    /**
     * Tick an answer, or untick it.
     *
     * Clicking the answer you already picked takes the vote off: the way back
     * is the same gesture as the way in, which is how the tag filter used to
     * work and how a reaction still does.
     *
     * On a single-choice poll a new vote replaces the old one. That rule is the
     * reason this is an action and not two lines in a controller: the database
     * cannot enforce it — the unique index is on (option, user) and the poll is
     * a column further away — so it is kept here, inside a transaction, with
     * the rows locked. Without that, two tabs both read "no vote yet" and both
     * insert.
     *
     * @return bool Whether the option is ticked afterwards.
     */
    public function handle(Poll $poll, PollOption $option, User $voter): bool
    {
        return DB::transaction(function () use ($poll, $option, $voter): bool {
            $existing = PollVote::query()
                ->whereIn('poll_option_id', $poll->options()->select('id'))
                ->where('user_id', $voter->id)
                ->lockForUpdate()
                ->get();

            $onThisOne = $existing->firstWhere('poll_option_id', $option->id);

            if ($onThisOne !== null) {
                $onThisOne->delete();

                return false;
            }

            /*
             * One answer at a time, unless the poll says otherwise. The old
             * votes go rather than the new one being refused: somebody
             * changing their mind is the ordinary case, and an error message
             * telling them to untick first would be the machine's problem
             * dressed up as theirs.
             */
            if (! $poll->allows_multiple) {
                $existing->each->delete();
            }

            PollVote::create([
                'poll_option_id' => $option->id,
                'user_id' => $voter->id,
            ]);

            return true;
        });
    }
}
