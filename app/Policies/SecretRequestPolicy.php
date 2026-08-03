<?php

namespace App\Policies;

use App\Models\SecretRequest;
use App\Models\User;

/**
 * Who may do what with a request for secrets.
 *
 * The unusual shape here is that reading is the narrowest right rather than the
 * widest. In most of this application a workspace admin can see what members
 * can; here they cannot, and that is the point — "somebody senior should be
 * able to look" is exactly the property that makes a store of other people's
 * passwords dangerous.
 */
class SecretRequestPolicy
{
    /**
     * Reading the answers. Only the person who asked.
     *
     * Not the channel's manager, not a workspace admin, not a platform
     * moderator. Every one of those would be a second person holding a
     * customer's credentials without the customer ever being told, and the
     * value of this feature over a chat message is precisely that the list of
     * people who can read it is one name long.
     */
    public function view(User $user, SecretRequest $request): bool
    {
        return $request->created_by === $user->id;
    }

    /**
     * Withdrawing it, or changing it. The same one person.
     *
     * An admin is deliberately not here either. Withdrawing is harmless enough
     * on its own, but an ability granted "just for tidying up" is how the read
     * right gets argued for later.
     */
    public function update(User $user, SecretRequest $request): bool
    {
        return $this->view($user, $request);
    }

    /**
     * Opening the form at all.
     *
     * Wider than fill() on purpose, in two directions: a closed request still
     * has to render — otherwise somebody following the link from the channel
     * gets a bare 404 instead of being told it was withdrawn — and the
     * requester may look at their own question even though they never answer
     * it. What none of them see here is a value.
     */
    public function viewForm(User $user, SecretRequest $request): bool
    {
        return $this->view($user, $request) || $user->can('view', $request->channel);
    }

    /**
     * Answering it.
     *
     * Anybody who can see the channel it was asked in, guests included — a
     * guest is usually the whole point, being the customer who holds the
     * credentials. Note this says nothing about answering *again*: a key that
     * already has a value is refused by the database, not here.
     */
    public function fill(User $user, SecretRequest $request): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        return $user->can('view', $request->channel);
    }
}
