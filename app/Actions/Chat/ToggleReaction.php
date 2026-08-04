<?php

namespace App\Actions\Chat;

use App\Events\ReactionAdded;
use App\Events\ReactionToggled;
use App\Models\Message;
use App\Models\User;

class ToggleReaction
{
    /**
     * Put an emoji on a message, or take it off again when it is already there.
     *
     * One endpoint for both directions keeps the browser honest: it never has to
     * decide whether it is adding or removing, so a stale render can't send the
     * wrong verb.
     *
     * @return bool True when the reaction was added, false when it was removed.
     */
    public function handle(Message $message, User $user, string $emoji): bool
    {
        $existing = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            $this->announce($message);

            return false;
        }

        // Two clicks arriving at once would both read "not reacted yet", so the
        // unique index on (message_id, user_id, emoji) is what actually keeps
        // the pair single. createOrFirst leans on that index instead of on the
        // read above, and hands back the winning row rather than a 500.
        $message->reactions()->createOrFirst([
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        $this->announce($message);

        ReactionAdded::dispatch($message, $user, $emoji);

        return true;
    }

    /**
     * Put a reaction there, and leave it alone if it already is.
     *
     * Beside handle() rather than through it, because a workflow says "react
     * with this" and means it every time it runs. Toggling would make the
     * second run undo the first, which is what a button means and not what a
     * rule does.
     */
    public function add(Message $message, User $user, string $emoji): void
    {
        $message->reactions()->createOrFirst([
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        $this->announce($message);

        ReactionAdded::dispatch($message, $user, $emoji);
    }

    /**
     * Take one off, and say nothing if it was not there.
     *
     * Only ever this person's own: the unique index is on the three of them
     * together, so there is no way to phrase this that would reach somebody
     * else's reaction.
     */
    public function remove(Message $message, User $user, string $emoji): void
    {
        $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->delete();

        $this->announce($message);
    }

    /**
     * Tell everyone with this channel open about the message's new reaction set.
     *
     * The relation is reloaded first: it may have been read before the toggle,
     * and a stale copy would broadcast the state from a moment ago.
     */
    private function announce(Message $message): void
    {
        $message->load('reactions');

        ReactionToggled::dispatch($message);
    }
}
