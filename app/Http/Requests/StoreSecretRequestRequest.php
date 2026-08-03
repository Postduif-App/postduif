<?php

namespace App\Http\Requests;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSecretRequestRequest extends FormRequest
{
    /** More than this is a config file, and a config file wants a transfer. */
    private const MAX_KEYS = 30;

    /** A month. Past that, "this expires" stops being what protects the values. */
    private const MAX_VALID_DAYS = 30;

    public function authorize(): bool
    {
        $channel = $this->route('channel');

        /*
         * Two questions, and both have to hold. Asking is a workspace-level
         * ability; putting the question in this particular channel is posting
         * in it, which a member may not be allowed to do everywhere.
         */
        return $this->user()->can('createSecretRequest', $this->route('workspace'))
            && $channel instanceof Channel
            && $this->user()->can('post', $channel);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'keys' => ['required', 'array', 'min:1', 'max:'.self::MAX_KEYS],
            /*
             * The shape of an environment variable, which is what these are.
             * Restricted rather than free text because the name is shown back
             * to whoever fills the form, and a "name" that is a sentence of
             * instructions is a way to put words in the requester's mouth.
             */
            'keys.*' => ['string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_.-]*$/'],

            /*
             * Required, and capped. This is the limit that actually protects
             * the values — see the migration — so there is no "never" and no
             * leaving it out.
             */
            'valid_for_days' => ['required', 'integer', 'min:1', 'max:'.self::MAX_VALID_DAYS],

            'burn_after_reading' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keys.required' => 'Noem minstens één sleutel om naar te vragen.',
            'keys.max' => 'Maximaal '.self::MAX_KEYS.' sleutels per verzoek.',
            'keys.*.regex' => 'Een sleutel begint met een letter en bevat verder letters, cijfers, _, . of -.',
            'valid_for_days.max' => 'Een verzoek blijft maximaal '.self::MAX_VALID_DAYS.' dagen open.',
        ];
    }
}
