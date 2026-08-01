<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
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
        ];
    }
}
