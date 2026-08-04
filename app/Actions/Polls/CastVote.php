<?php

namespace App\Actions\Polls;

use App\Actions\Chat\AnnounceInbox;
use App\Enums\InboxItemType;
use App\Models\InboxItem;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CastVote
{
    public function __construct(private readonly AnnounceInbox $announceInbox) {}

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

            $this->tellTheAsker($poll, $voter);

            return true;
        });
    }

    /**
     * Put the vote in the inbox of whoever asked the question.
     *
     * Only on the way in. Unticking leaves through the early return above, and
     * a row that appeared when somebody changed their mind would be an inbox
     * that fills up with nothing having happened.
     *
     * One row per poll, bumped rather than added to: forty voters would
     * otherwise be forty lines, which is the point at which an inbox stops
     * being one. What the row carries is the poll, so the count it shows is
     * read off the votes when the inbox is opened rather than kept here.
     */
    private function tellTheAsker(Poll $poll, User $voter): void
    {
        // The asker may have left the workspace — the poll outlives them, but
        // there is nobody to tell. Nor does anyone need telling what they
        // themselves just ticked.
        if ($poll->created_by === null || $poll->created_by === $voter->id) {
            return;
        }

        if (! $poll->channel->members()->whereKey($poll->created_by)->exists()) {
            return;
        }

        InboxItem::updateOrCreate([
            'type' => InboxItemType::PollVote,
            'poll_id' => $poll->id,
            'user_id' => $poll->created_by,
        ], [
            'channel_id' => $poll->channel_id,
            /*
             * Left empty on purpose, where the other kinds name who acted. This
             * row stands for every vote on the poll, so filling it with the
             * most recent one would put a name on a line that speaks for
             * twelve — and the card already lists them all, by option.
             */
            'actor_id' => null,
            'read_at' => null,
        ]);

        $this->announceInbox->handle($poll->workspace_id, [$poll->created_by]);
    }
}
