<?php

namespace App\Policies;

use App\Features\MessageBoard;
use App\Models\BoardPost;
use App\Models\User;
use App\Models\Workspace;

class BoardPostPolicy
{
    /**
     * Seeing the prikbord at all.
     *
     * Two gates, and they answer different questions: the feature says this
     * workspace keeps a board, the role says this person is one of the people
     * it is for.
     *
     * Guests are out, and this is the one rule the whole feature is built
     * around. A guest is somebody from outside who was let into a few channels;
     * the board is the workspace talking to itself — the vakantieregeling, the
     * verhuizing, who is on call in augustus. None of that is a customer's
     * business, and unlike a channel there is no per-item invitation that could
     * make it theirs.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        if (! $workspace->hasFeature(MessageBoard::class)) {
            return false;
        }

        return $workspace->roleFor($user)?->canBrowseWorkspace() ?? false;
    }

    public function view(User $user, BoardPost $post): bool
    {
        return $this->viewAny($user, $post->workspace);
    }

    /**
     * Putting something up.
     *
     * Open to everybody who may read the board rather than to beheerders only.
     * A prikbord where one person decides what gets pinned up is a nieuwsbrief,
     * and a nieuwsbrief is a thing people stop reading. What keeps it in order
     * is pin() and delete() below, which are narrower.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $this->viewAny($user, $workspace);
    }

    /** Replying under a notice. The same people, for the same reason. */
    public function comment(User $user, BoardPost $post): bool
    {
        return $this->view($user, $post);
    }

    /**
     * Leaving an emoji under a notice.
     *
     * Its own ability rather than a second caller of comment(), even though the
     * two currently answer alike. They are different gestures — a reply is
     * addressed to the board, a reaction is addressed to the person who wrote
     * the notice — and a workspace that ever wants to close one without the
     * other should not have to take the pair apart first.
     */
    public function react(User $user, BoardPost $post): bool
    {
        return $this->view($user, $post);
    }

    /**
     * Correcting a notice after it went up.
     *
     * The author's own, plus whoever runs the workspace — a wrong date on a
     * notice everybody is reading is worth fixing by whoever spots it, and the
     * person who typed it is often exactly the one who is away. Every edit is
     * stamped, so neither of them can do it quietly.
     */
    public function update(User $user, BoardPost $post): bool
    {
        if (! $this->view($user, $post)) {
            return false;
        }

        return $post->user_id === $user->id
            || app(WorkspacePolicy::class)->manage($user, $post->workspace);
    }

    /** Taking it down again. The same two, for the same reason. */
    public function delete(User $user, BoardPost $post): bool
    {
        return $this->update($user, $post);
    }

    /**
     * Moving a notice to the top, above everything else on the board.
     *
     * Narrower than posting on purpose, and the only place this feature is:
     * pinning is not a claim about your own notice, it is a claim on everybody
     * else's attention. If anyone could make it, the top of the board would be
     * whoever pinned most recently rather than what actually matters — which is
     * the failure mode of every prikbord that has ever been ignored.
     */
    public function pin(User $user, BoardPost $post): bool
    {
        return $this->view($user, $post)
            && app(WorkspacePolicy::class)->manage($user, $post->workspace);
    }
}
