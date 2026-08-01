<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Str;

class GenerateHandle
{
    /**
     * Mentions need a short, unique handle. Derive one and add a counter until
     * it is free, rather than asking for it during sign-up.
     *
     * Shared between registration and invitation acceptance: both create an
     * account without putting the question to the person doing it, and a second
     * implementation would be a second set of rules about what a handle may be.
     */
    public function handle(string $name, string $email): string
    {
        $base = Str::of($name)->slug('.')->limit(30, '')->value()
            ?: Str::of($email)->before('@')->slug('.')->value();

        $handle = $base;
        $suffix = 1;

        while (in_array($handle, User::RESERVED_HANDLES, true) || User::where('username', $handle)->exists()) {
            $handle = $base.++$suffix;
        }

        return $handle;
    }
}
