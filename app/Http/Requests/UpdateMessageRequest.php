<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Message $message */
        $message = $this->route('message');

        return $this->user()->can('update', $message);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The same ceiling as a new message: an edit that could hold more than
        // the composer allows would be a way around the limit.
        return [
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
