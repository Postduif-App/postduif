<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreChannelLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', $this->route('channel'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:40'],
            /*
             * http and https only. The bar is drawn for everyone who can see
             * the channel, guests included, so a "javascript:" or "data:" here
             * would be an admin handing themselves a way to run something in
             * every reader's browser.
             */
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Geef de knop een naam.',
            'url.required' => 'Geef een adres op.',
            'url.url' => 'Dit moet een adres zijn dat met http:// of https:// begint.',
        ];
    }
}
