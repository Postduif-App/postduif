<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Http\Middleware\HandleLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /** What the language select sends when nothing was chosen. */
    public const FOLLOW_BROWSER = 'auto';

    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    /**
     * "Follow my browser" arrives as a value because a select cannot send
     * nothing, and is stored as the absence of one.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('locale') === self::FOLLOW_BROWSER) {
            $this->merge(['locale' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            /*
             * Here rather than in profileRules, which registration shares:
             * asking somebody for their timezone before they have an account is
             * a question in the way of the thing they came to do, and the
             * default covers them until they care.
             */
            'timezone' => ['required', 'timezone'],

            /*
             * Nullable, and the null is the useful answer rather than a gap:
             * it means "follow my browser". Somebody who never picks a language
             * should keep getting the one their browser asks for, including
             * when they change it.
             */
            'locale' => ['nullable', Rule::in(HandleLocale::SUPPORTED)],

            /*
             * A few lines beside a name, not a career history — see the
             * migration for why 280 rather than a text column.
             *
             * Nothing here turns a blank one into null: TrimStrings and
             * ConvertEmptyStringsToNull are both in Laravel's default stack and
             * have already done it by the time this runs. Doing it again would
             * be code that looks load-bearing and is not.
             */
            'bio' => ['nullable', 'string', 'max:280'],
        ];
    }
}
