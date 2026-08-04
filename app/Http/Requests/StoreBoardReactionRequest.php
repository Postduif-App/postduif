<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardReactionRequest extends FormRequest
{
    /**
     * Reacting asks the same question reading does, and no more.
     *
     * Not `comment`, even though the two happen in the same place: replying is
     * writing something under somebody's notice, reacting is the gesture people
     * make instead — and a board where you may only agree by writing a
     * paragraph is a board with no agreement on it.
     */
    public function authorize(): bool
    {
        return $this->user()->can('react', $this->route('board_post'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The same rule the channel reactions use, word for word: a pill is
            // a symbol, not a label. Refusing letters, digits and whitespace
            // keeps "lgtm" out of the row without pinning the column to a fixed
            // list the picker would outgrow.
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
