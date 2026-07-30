<?php

namespace App\Http\Requests;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', $this->route('channel'));
    }

    /**
     * Ids are compared as strings to work out what a member has already read,
     * and PHP compares them byte for byte — where every uppercase letter sorts
     * before every lowercase one. Laravel stores lowercase ULIDs, so anything
     * arriving in another case would compare as older than every existing
     * message. Normalise on the way in rather than trusting the client.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'id' => $this->string('id')->lower()->value(),
            'parent_id' => $this->string('parent_id')->lower()->value() ?: null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        return [
            'id' => ['required', 'string', 'size:26', 'ulid', Rule::unique('messages', 'id')],
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => [
                'nullable',
                'ulid',
                Rule::exists('messages', 'id')
                    ->where('channel_id', $channel->id)
                    ->whereNull('parent_id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'Je kunt alleen antwoorden op een bericht in dit kanaal.',
        ];
    }
}
