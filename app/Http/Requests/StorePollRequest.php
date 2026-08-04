<?php

namespace App\Http\Requests;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePollRequest extends FormRequest
{
    /** Past this a poll is a survey, and a survey wants a different tool. */
    private const MAX_OPTIONS = 12;

    public function authorize(): bool
    {
        $channel = $this->route('channel');

        /*
         * Two questions. Asking is a workspace ability; putting the question in
         * this channel is posting in it, which a member may not be allowed to
         * do everywhere — the same pair a transfer announcement checks.
         */
        return $this->user()->can('createPoll', $this->route('workspace'))
            && $channel instanceof Channel
            && $this->user()->can('post', $channel);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:200'],

            // Two, not one: a poll with a single answer is not a question.
            'options' => ['required', 'array', 'min:2', 'max:'.self::MAX_OPTIONS],
            'options.*' => ['string', 'max:80'],

            'allows_multiple' => ['sometimes', 'boolean'],

            /*
             * Hours rather than a moment. What somebody is deciding is how long
             * the channel gets to answer, and a dropdown of that is one choice
             * instead of a date picker and a sum. A week is the ceiling: past
             * that nobody remembers there was a question.
             */
            'closes_in_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.required' => __('requests.poll.options_required'),
            'options.min' => __('requests.poll.options_min'),
            'options.max' => __('requests.poll.options_max', ['count' => self::MAX_OPTIONS]),
        ];
    }
}
