<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * What a handle may be.
     *
     * Beside the name and e-mail rules rather than written where it is used,
     * because a handle is the one field on a user that another piece of the
     * application has to be able to find again: RecordMentions looks for
     * exactly this shape after an "@" — letters, digits, dashes and
     * underscores, in dot-separated parts. A handle outside it is a handle
     * nobody can mention, which is most of what a handle is for. The two must
     * stay in step.
     *
     * Lowercase, because that is how it is stored and how mentions are looked
     * up; whoever calls this lowercases the input first rather than refusing a
     * capital, which would be a rule about typing rather than about handles.
     *
     * Thirty characters, the length GenerateHandle already trims to, so a
     * handle somebody picks cannot be longer than one the application would
     * have made for them.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function handleRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'max:30',
            'regex:/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/',
            // @everyone and @here address a group; a person holding one of
            // those would make every broadcast ambiguous.
            Rule::notIn(User::RESERVED_HANDLES),
            $userId === null
                ? Rule::unique(User::class, 'username')
                : Rule::unique(User::class, 'username')->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
