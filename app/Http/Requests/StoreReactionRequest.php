<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReactionRequest extends FormRequest
{
    /**
     * Reacting needs membership — reading a public channel is open to the whole
     * workspace, leaving something behind is not — but not permission to post:
     * in a channel only admins may write in, everyone may still react.
     */
    public function authorize(): bool
    {
        return $this->user()->can('react', $this->route('channel'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A pill is a symbol, not a label. Refusing letters, digits and
            // whitespace keeps "lgtm" out of the reaction row without pinning
            // the column to a fixed emoji list the picker would outgrow.
            'emoji' => ['required', 'string', 'max:32', 'not_regex:/[\s\w]/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emoji.not_regex' => __('requests.reaction.emoji_only'),
        ];
    }
}
