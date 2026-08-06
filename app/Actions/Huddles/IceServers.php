<?php

namespace App\Actions\Huddles;

use App\Models\User;

/**
 * The servers a browser should use to reach the others.
 *
 * Worked out per request rather than sent once at build time, because half of
 * the answer is a credential that expires — see below — and the other half is a
 * deployment setting that must be changeable without rebuilding the frontend.
 */
class IceServers
{
    /**
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function handle(User $user): array
    {
        $servers = [];

        $stun = config('huddles.stun_urls');

        if ($stun !== []) {
            $servers[] = ['urls' => $stun];
        }

        $turn = config('huddles.turn_urls');

        if ($turn === []) {
            return $servers;
        }

        $servers[] = ['urls' => $turn, ...$this->credential($user)];

        return $servers;
    }

    /**
     * Whether a huddle can be expected to work at all.
     *
     * Asked out loud so the screen can say "dit is nog niet ingericht" rather
     * than offering a button that connects for the two colleagues on the same
     * wifi and silently fails for everybody at home. STUN is the floor: without
     * it there is nothing to try beyond the local network.
     */
    public function configured(): bool
    {
        return config('huddles.stun_urls') !== [];
    }

    /**
     * A username and password for the relay that stops working by itself.
     *
     * coturn's REST scheme: the username is an expiry stamped with who it was
     * for, and the password is that username signed with a secret only the
     * server and the relay know. Nothing has to be stored on either side, and a
     * credential lifted out of a browser is worthless within the hour.
     *
     * The user id rides along in the name so a relay's logs can say who used
     * it. It is not a secret — the signature is what makes the name usable.
     *
     * @return array{username?: string, credential?: string}
     */
    private function credential(User $user): array
    {
        $secret = config('huddles.turn_secret');

        if (! is_string($secret) || $secret === '') {
            return [];
        }

        $username = now()->addMinutes((int) config('huddles.turn_ttl_minutes'))->timestamp.':'.$user->id;

        return [
            'username' => $username,
            // sha1 and base64 are not a choice here: it is what coturn checks.
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, binary: true)),
        ];
    }
}
