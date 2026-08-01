<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Models\Channel;
use App\Models\Ticket;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Ticket::class, $this->route('channel')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Channel $channel */
        $channel = $this->route('channel');

        return [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:4000'],

            // Only members set this, so it is optional rather than required: a
            // guest's form does not offer the field at all and their ticket
            // simply comes in as normal.
            'priority' => ['sometimes', new Enum(TicketPriority::class)],

            /*
             * The channel check is a boundary, not tidiness: the promoted
             * message's text is shown back on the ticket, so without it somebody
             * could lift a line out of a conversation they were never part of.
             */
            'source_message_id' => [
                'nullable',
                'ulid',
                Rule::exists('messages', 'id')->where('channel_id', $channel->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Geef het ticket een korte titel.',
            'source_message_id.exists' => 'Je kunt alleen een bericht uit dit kanaal promoveren.',
        ];
    }
}
