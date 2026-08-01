<?php

namespace App\Http\Requests;

use App\Models\Channel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledMessageRequest extends FormRequest
{
    /**
     * The same ability as posting now.
     *
     * Scheduling is saying something in a channel with a delay, so it cannot be
     * a way around a posting policy. It is checked again when the moment
     * arrives — see DispatchScheduledMessages — because a week is long enough
     * for the answer to change.
     */
    public function authorize(): bool
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        return $this->user()->can('post', $channel);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
            /*
             * A minute out, not merely "after now": the browser sends a moment
             * it worked out a second or two ago, and rejecting 09:00 at 09:00
             * for being a hair late is a rule nobody can satisfy.
             */
            'send_at' => ['required', 'date', 'after:'.now()->addMinute()->toDateTimeString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Schrijf eerst een bericht.',
            'send_at.after' => 'Kies een moment dat nog moet komen.',
        ];
    }
}
