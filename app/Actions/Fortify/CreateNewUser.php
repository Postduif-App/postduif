<?php

namespace App\Actions\Fortify;

use App\Actions\Users\GenerateHandle;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private GenerateHandle $generateHandle) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // The endpoint as well as the page. Hiding the form is presentation;
        // this is the part that decides whether an account can exist, and a
        // posted form does not go through the view.
        abort_unless(config('auth.registration_open'), 404);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'username' => $this->generateHandle->handle($input['name'], $input['email']),
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
