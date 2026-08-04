<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBoardCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('comment', $this->route('board_post'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // No title, and far shorter than the notice it hangs under: this is
            // a remark, and a reply long enough to be a notice of its own should
            // be one.
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
