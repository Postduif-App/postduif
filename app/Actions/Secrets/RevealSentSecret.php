<?php

namespace App\Actions\Secrets;

use App\Models\SentSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Hand a secret over, once, and make sure there is nothing left to hand over
 * again.
 *
 * The whole feature lives or dies here. "Read once" enforced by a check in PHP
 * is a promise two browser tabs can break: both read revealed_at as null within
 * the same millisecond, both decide they are first, both get the ciphertext.
 * So the row is locked for update, re-read inside the transaction, and blanked
 * before the transaction closes — the second tab waits at the lock and then
 * finds a row that has already been spent.
 *
 * The ciphertext is overwritten rather than the row deleted, for two reasons:
 * the sender should be able to see that it arrived, and the retrieval screen
 * has to be able to say "dit is al opgehaald" instead of a 404, which would be
 * indistinguishable from a mistyped link.
 */
class RevealSentSecret
{
    /**
     * What came back, and why not when nothing did.
     *
     * A result rather than an exception per case: none of these are faults. A
     * secret that has been read, or whose moment passed, is information the
     * screen has to put into a sentence — see the controller.
     *
     * @return array{ok: bool, reason: string|null, ciphertext: string|null, iv: string|null}
     */
    public function handle(SentSecret $secret, ?string $password = null): array
    {
        return DB::transaction(function () use ($secret, $password): array {
            /*
             * Locked and re-read, not trusted from the caller. The instance that
             * arrived came from route binding, which read the row before any of
             * this began — and "before" is exactly the window this action exists
             * to close.
             */
            $fresh = SentSecret::query()
                ->whereKey($secret->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return $this->refuse('gone');
            }

            if ($fresh->isRevealed()) {
                return $this->refuse('revealed');
            }

            if ($fresh->isExpired()) {
                return $this->refuse('expired');
            }

            /*
             * The password is checked inside the lock but before anything is
             * spent: a wrong guess must leave the secret exactly as it was, or
             * the gate meant to protect it becomes the way to destroy it.
             */
            if ($fresh->needsPassword() && ! Hash::check((string) $password, (string) $fresh->password_hash)) {
                return $this->refuse('password');
            }

            $ciphertext = $fresh->ciphertext;
            $iv = $fresh->iv;

            /*
             * Blanked in the same transaction that reads it. Empty strings
             * rather than null: the columns are not nullable, and a row that
             * still has its shape is one the retrieval screen can keep
             * answering questions about.
             */
            $fresh->forceFill([
                'ciphertext' => '',
                'iv' => '',
                'password_hash' => null,
                'revealed_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'reason' => null,
                'ciphertext' => $ciphertext,
                'iv' => $iv,
            ];
        });
    }

    /**
     * @return array{ok: bool, reason: string|null, ciphertext: string|null, iv: string|null}
     */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'ciphertext' => null, 'iv' => null];
    }
}
