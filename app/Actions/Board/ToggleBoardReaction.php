<?php

namespace App\Actions\Board;

use App\Models\BoardPost;
use App\Models\User;

class ToggleBoardReaction
{
    /**
     * Put an emoji under a notice, or take it off again when it is already
     * there.
     *
     * The same bargain ToggleReaction strikes for messages, and for the same
     * reason: one endpoint for both directions means the browser never has to
     * decide whether it is adding or removing, so a page rendered a minute ago
     * cannot send the wrong verb.
     *
     * What it deliberately does not do is announce anything. A message reaction
     * is broadcast because a channel is being watched live by people who are
     * reading it right now; a prikbord is a wall somebody walks past. The page
     * reloads the notice on every write anyway — see the panel's preserveState
     * visits — so the person who clicked sees it, and everybody else sees it
     * next time they look. That is what a board is.
     *
     * @return bool True when the reaction was added, false when it was removed.
     */
    public function handle(BoardPost $post, User $user, string $emoji): bool
    {
        $existing = $post->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        // Leans on the unique index rather than on the read above: two clicks
        // arriving together would both find nothing, and createOrFirst hands
        // back the winner instead of a 500.
        $post->reactions()->createOrFirst([
            'user_id' => $user->id,
            'emoji' => $emoji,
        ]);

        return true;
    }
}
