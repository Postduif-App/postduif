<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'username' => $this->availableHandle($input['name'], $input['email']),
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }

    /**
     * Mentions need a short, unique handle. Derive one and add a counter until
     * it is free, rather than asking for it during sign-up.
     */
    private function availableHandle(string $name, string $email): string
    {
        $base = Str::of($name)->slug('.')->limit(30, '')->value()
            ?: Str::of($email)->before('@')->slug('.')->value();

        $handle = $base;
        $suffix = 1;

        while (User::where('username', $handle)->exists()) {
            $handle = $base.++$suffix;
        }

        return $handle;
    }
}
