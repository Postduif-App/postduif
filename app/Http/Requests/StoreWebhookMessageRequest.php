<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookMessageRequest extends FormRequest
{
    /**
     * The token in the URL is the whole of the authorisation, and the
     * controller is what checks it — there is no user here to ask.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "text" rather than "body", unlike StoreMessageRequest.
     *
     * This is an outward-facing contract aimed at things that already speak
     * webhook, so it follows the convention those tools emit rather than the
     * column name we happen to use internally.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'Stuur een "text" mee met de inhoud van het bericht.',
        ];
    }
}
