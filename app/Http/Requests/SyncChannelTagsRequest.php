<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncChannelTagsRequest extends FormRequest
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
            // Present but empty is how a channel loses its last tag, so the
            // array itself is required and its contents are not.
            'tags' => ['present', 'array', 'max:20'],
            /*
             * Nullable, because Laravel turns an empty field into null before
             * this runs — so a picker that submits a blank row would otherwise
             * bounce the whole request back with an error nobody can act on.
             * Blanks are dropped in SyncChannelTags, where trimming already
             * happens.
             */
            'tags.*' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tags.max' => __('requests.channel_tags.too_many'),
        ];
    }
}
