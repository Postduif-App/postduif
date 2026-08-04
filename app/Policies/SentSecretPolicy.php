<?php

namespace App\Policies;

use App\Models\SentSecret;
use App\Models\User;

/**
 * Who may send a secret, and who may take one back.
 *
 * Note which question is absent: who may read one. That is not a policy at all
 * here, because there is nobody to check — the link carries the key, and
 * somebody holding it may well have no account. Reading is guarded by what the
 * holder knows rather than by who they are, which is the trade the whole feature
 * was chosen for. See RevealSentSecret for the guards that do apply.
 */
class SentSecretPolicy
{
    /**
     * Withdrawing one before it is read.
     *
     * The sender's alone — not the channel's admins, and not the recipient. A
     * beheerder who could withdraw could also deny somebody the credential they
     * were promised without leaving a trace of having done it, and the recipient
     * withdrawing their own secret is simply a stranger way of not reading it.
     */
    public function withdraw(User $user, SentSecret $secret): bool
    {
        return $secret->created_by === $user->id && $secret->isPending();
    }
}
