<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Being somebody else for a while, and finding the way back.
 *
 * All of impersonation that is not a policy question lives here: two writes to
 * the session and the one fact everything else reads off it. Its own class
 * rather than a pair of controller methods, because three separate places have
 * to agree about that fact — the controller that starts it, the middleware that
 * keeps the identity screens shut while it runs, and the shared Inertia props
 * that draw the bar reminding you it is running. A session key spelled out in
 * three files is a session key that gets misspelled in one of them.
 *
 * What is deliberately *not* stored is anything about the person being
 * impersonated: the guard already knows who is signed in, and a second copy of
 * that would be a copy that can disagree with it. Only the way back is kept,
 * as an id and not a model — a session holds strings, and a serialised user is
 * a stale user.
 */
class Impersonation
{
    /**
     * Who to put back. Its presence is the whole state: absent means nobody is
     * impersonating anybody, which is what makes every read below a one-liner.
     */
    private const SESSION_KEY = 'impersonator_id';

    public function __construct(private readonly Session $session) {}

    /**
     * Step into somebody else's session.
     *
     * The order matters. Auth::login() migrates the session — a new id for the
     * same data, which is what keeps a stolen cookie from following the switch
     * — and only then is the way back written, so the note about who we were
     * lands in the session that will actually be read next request.
     *
     * Two things are dropped on the way in. A confirmed password is the
     * impersonator's own and would otherwise let them straight past
     * RequirePassword on screens that ask for it, and a remembered login would
     * write the wrong identity into a cookie that outlives the session — this
     * is a visit, not a sign-in.
     */
    public function begin(User $impersonator, User $target): void
    {
        Auth::guard('web')->login($target);

        $this->session->put(self::SESSION_KEY, $impersonator->getKey());
        $this->session->forget('auth.password_confirmed_at');
    }

    /**
     * Put the original member back, and say who that was.
     *
     * Null when there is nothing to end, so a stop request that arrives twice —
     * two tabs, a double click — is a no-op rather than an error.
     *
     * The key is pulled before the user is looked up: an impersonator whose
     * account has since been deleted or suspended must not leave a session that
     * keeps offering the way back to nowhere. That case ends the impersonation
     * without restoring anybody, and the caller signs the session out.
     */
    public function stop(): ?User
    {
        $id = $this->session->pull(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        $impersonator = User::query()->find($id);

        if ($impersonator === null || $impersonator->isSuspended()) {
            return null;
        }

        Auth::guard('web')->login($impersonator);

        return $impersonator;
    }

    /** Whether this session is somebody looking through another's eyes. */
    public function isActive(): bool
    {
        return $this->session->has(self::SESSION_KEY);
    }

    /**
     * The member who started it, for the bar that says so.
     *
     * A query per request while it runs, which is a price only the sessions
     * that are actually impersonating pay — every other request answers false
     * above and never gets here.
     */
    public function impersonator(): ?User
    {
        $id = $this->session->get(self::SESSION_KEY);

        return $id === null ? null : User::query()->find($id);
    }
}
